import { ref, computed, watch, type Ref } from "vue";
import { api } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";

/**
 * The org hierarchy as three dependent selects: Function › Department ›
 * Business unit. Give it the three model refs and it keeps them consistent —
 * choosing a lower level fills the parents, changing a parent drops a child
 * that no longer belongs. Used for both "assign to" and filtering by it.
 */
interface Unit { id: number; name: string }
interface Dept extends Unit { functional_unit_id: number | null }
interface Bu extends Unit { department_id: number | null }
interface RawBu { id: number; name: string }
interface RawDept { id: number; name: string; functional_unit_id: number | null; business_units?: RawBu[] }

export function useOrgHierarchy(
  functionalUnitId: Ref<number | null>,
  departmentId: Ref<number | null>,
  businessUnitId: Ref<number | null>,
) {
  const functionalUnits  = ref<Unit[]>([]);
  const allDepartments   = ref<Dept[]>([]);
  const allBusinessUnits = ref<Bu[]>([]);
  let loaded = false;

  async function load() {
    if (loaded) return;
    loaded = true;
    try {
      const res = await api<{
        functional_units: { id: number; name: string; departments: RawDept[] }[];
        unassigned_departments: RawDept[];
      }>("GET", "/api/v1/org/structure", { org: currentOrgSlug.value });

      functionalUnits.value = res.functional_units.map((u) => ({ id: u.id, name: u.name }));

      const depts: Dept[] = [];
      const bus: Bu[] = [];
      const collect = (d: RawDept) => {
        depts.push({ id: d.id, name: d.name, functional_unit_id: d.functional_unit_id ?? null });
        for (const bu of d.business_units ?? [])
          bus.push({ id: bu.id, name: bu.name, department_id: d.id });
      };
      for (const u of res.functional_units) for (const d of u.departments ?? []) collect(d);
      for (const d of res.unassigned_departments ?? []) collect(d);
      allDepartments.value   = depts;
      allBusinessUnits.value = bus;
    } catch { loaded = false; /* let a later call retry */ }
  }

  // Each level offers only what sits under the level above; with nothing chosen
  // above, it offers everything.
  const departmentOptions = computed(() => functionalUnitId.value
    ? allDepartments.value.filter((d) => d.functional_unit_id === functionalUnitId.value)
    : allDepartments.value);
  const businessUnitOptions = computed(() => departmentId.value
    ? allBusinessUnits.value.filter((b) => b.department_id === departmentId.value)
    : allBusinessUnits.value);

  watch(businessUnitId, (id) => {
    const b = allBusinessUnits.value.find((x) => x.id === id);
    if (b?.department_id) departmentId.value = b.department_id;
  });
  watch(departmentId, (id) => {
    const d = allDepartments.value.find((x) => x.id === id);
    if (d?.functional_unit_id) functionalUnitId.value = d.functional_unit_id;
    if (businessUnitId.value && !allBusinessUnits.value.some((b) => b.id === businessUnitId.value && b.department_id === id))
      businessUnitId.value = null;
  });
  watch(functionalUnitId, (id) => {
    const d = allDepartments.value.find((x) => x.id === departmentId.value);
    if (departmentId.value && d && d.functional_unit_id !== id) {
      departmentId.value = null;
      businessUnitId.value = null;
    }
  });

  return { functionalUnits, departmentOptions, businessUnitOptions, load };
}
