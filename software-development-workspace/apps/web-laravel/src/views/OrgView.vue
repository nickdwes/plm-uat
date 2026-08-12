<script setup lang="ts">
import { ref, onMounted, watch, computed } from "vue";
import { api, ApiError } from "../lib/api";
import { currentOrgSlug, authStore, updateOrgBranding, getToken } from "../stores/auth";
import type { ComplianceDocumentRecord, ComplianceDocumentListData, ComplianceSummary, OrgStructureData, OrgFunctionalUnit, OrgDepartment, OrgBusinessUnit, OrgPractice } from "../lib/types";

interface Designation   { id: number; title: string; description: string | null }
interface JobGrade      { id: number; name: string; level: number; min_salary: number | null; max_salary: number | null }
interface JobCategory   { id: number; name: string; description: string | null }
interface OrgProfile    { id: number; name: string; slug: string; legal_name: string | null; industry: string | null; country: string; currency: string; primary_email: string | null; primary_phone: string | null; address: string | null; is_active: boolean; logo_url?: string | null; primary_color?: string | null }

const tab         = ref<"profile" | "departments" | "designations" | "grades" | "categories" | "asset-categories">("profile");
const profileTab  = ref<"overview" | "compliance">("overview");
const designations  = ref<Designation[]>([]);
const jobGrades     = ref<JobGrade[]>([]);
const jobCategories = ref<JobCategory[]>([]);

/**
 * The kinds of thing the organisation owns.
 *
 * These were a hardcoded list in the assets screen, so adding "UPS" meant a
 * developer and a deploy. Configured here instead, per organisation, because
 * the tenants own genuinely different equipment.
 */
interface AssetCategory {
  id: number; asset_class: string; name: string;
  description: string | null; is_active: boolean;
}

const ASSET_CLASSES = [
  { key: "physical",     label: "Physical" },
  { key: "software",     label: "Software" },
  { key: "subscription", label: "Subscription" },
];

const assetCategories = ref<AssetCategory[]>([]);
const acForm  = ref({ asset_class: "physical", name: "", description: "" });
const acError = ref("");

const assetCategoriesIn = (cls: string) =>
  assetCategories.value.filter(c => c.asset_class === cls);

async function loadAssetCategories(): Promise<void> {
  const res = await api<{ data: AssetCategory[] }>("GET", "/api/v1/org/asset-categories", { org: org() });
  assetCategories.value = res.data ?? [];
}

async function addAssetCategory(): Promise<void> {
  acError.value = "";
  if (!acForm.value.name.trim()) return;
  saving.value = true;
  try {
    await api("POST", "/api/v1/org/asset-categories", { org: org(), body: acForm.value });
    acForm.value.name = ""; acForm.value.description = "";
    await loadAssetCategories();
  } catch (e) {
    acError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not add that category.";
  } finally { saving.value = false; }
}

/** Switching one off leaves history readable and takes it out of the picker. */
async function toggleAssetCategory(c: AssetCategory): Promise<void> {
  await api("PUT", `/api/v1/org/asset-categories/${c.id}`, {
    org: org(), body: { is_active: !c.is_active },
  });
  await loadAssetCategories();
}

/**
 * Deleting one that is in use retires it instead, and the server says so.
 *
 * Assets record a category by name, so removing it outright would leave those
 * rows pointing at nothing.
 */
async function removeAssetCategory(c: AssetCategory): Promise<void> {
  const res = await api<{ message: string; retired: boolean }>(
    "DELETE", `/api/v1/org/asset-categories/${c.id}`, { org: org() },
  );
  if (res.retired) acError.value = res.message;
  await loadAssetCategories();
}
const allOrgs     = ref<OrgProfile[]>([]);
const loading     = ref(false);
const error       = ref("");
const saving      = ref(false);
const saveError   = ref("");
const saveSuccess = ref("");

// ── Profile edit ─────────────────────────────────────────────────────────────
const profileEdit = ref(false);
const profileForm = ref({ name: "", legal_name: "", industry: "", country: "KE", currency: "KES", primary_email: "", primary_phone: "", address: "", primary_color: "" });

// ── Logo upload ───────────────────────────────────────────────────────────────
const logoUploading = ref(false);
const logoError     = ref("");
const logoFileInput = ref<HTMLInputElement | null>(null);

async function uploadLogo(e: Event): Promise<void> {
  const file = (e.target as HTMLInputElement).files?.[0];
  if (!file || !currentOrgProfile.value) return;
  logoUploading.value = true;
  logoError.value = "";
  try {
    const form = new FormData();
    form.append("logo", file);
    const res = await fetch(`/api/v1/organizations/${currentOrgProfile.value.id}/logo`, {
      method: "POST",
      headers: { "Accept": "application/json", "Authorization": `Bearer ${getToken() ?? ""}` },
      body: form,
    });
    if (!res.ok) throw new Error("Upload failed");
    const data = await res.json() as { logo_url: string; primary_color?: string | null };
    updateOrgBranding(currentOrgProfile.value.id, { logo_url: data.logo_url });
    await loadOrgs();
    saveSuccess.value = "Logo updated successfully.";
  } catch {
    logoError.value = "Logo upload failed. Please try again.";
  } finally {
    logoUploading.value = false;
    if (logoFileInput.value) logoFileInput.value.value = "";
  }
}

const currentOrgProfile = computed<OrgProfile | undefined>(() =>
  allOrgs.value.find(o => o.slug === currentOrgSlug.value)
);

function startProfileEdit(): void {
  const p = currentOrgProfile.value;
  if (!p) return;
  profileForm.value = {
    name: p.name, legal_name: p.legal_name ?? "", industry: p.industry ?? "",
    country: p.country, currency: p.currency,
    primary_email: p.primary_email ?? "", primary_phone: p.primary_phone ?? "",
    address: p.address ?? "",
    primary_color: p.primary_color || "#004C97",
  };
  profileEdit.value = true;
}

async function saveProfile(): Promise<void> {
  const p = currentOrgProfile.value;
  if (!p) return;
  saving.value = true; saveError.value = ""; saveSuccess.value = "";
  try {
    const res = await api<{ data: OrgProfile }>("PUT", `/api/v1/organizations/${p.id}`, { body: profileForm.value });
    profileEdit.value = false;
    saveSuccess.value = "Organisation profile updated.";
    // Push branding changes to the reactive store immediately
    if (res.data) {
      updateOrgBranding(p.id, {
        logo_url:      res.data.logo_url,
        primary_color: res.data.primary_color,
      });
    }
    await loadOrgs();
  } catch (e) { saveError.value = e instanceof Error ? e.message : "Failed to save."; }
  finally { saving.value = false; }
}

// ── Register new organisation ─────────────────────────────────────────────────
const showNewOrg  = ref(false);
const newOrgForm  = ref({ name: "", legal_name: "", industry: "", country: "KE", currency: "KES", primary_email: "", primary_phone: "", address: "" });
const newOrgError = ref("");
const newOrgSaving= ref(false);

function openNewOrg(): void { showNewOrg.value = true; newOrgError.value = ""; }
function closeNewOrg(): void { showNewOrg.value = false; }

async function registerOrg(): Promise<void> {
  if (!newOrgForm.value.name.trim()) { newOrgError.value = "Organisation name is required."; return; }
  newOrgSaving.value = true; newOrgError.value = "";
  try {
    await api("POST", "/api/v1/organizations", { body: newOrgForm.value });
    newOrgForm.value = { name: "", legal_name: "", industry: "", country: "KE", currency: "KES", primary_email: "", primary_phone: "", address: "" };
    showNewOrg.value = false;
    saveSuccess.value = "New organisation registered. Please refresh the page to switch to it.";
    await loadOrgs();
  } catch (e) { newOrgError.value = e instanceof Error ? e.message : "Failed to register."; }
  finally { newOrgSaving.value = false; }
}

// Inline edit state
const editCatId      = ref<number | null>(null);
const editCat        = ref({ name: "", description: "" });
const selectedCatIds = ref<Set<number>>(new Set());
const editDesigId    = ref<number | null>(null);
const editDesig      = ref({ title: "", description: "" });
const editGradeId    = ref<number | null>(null);
const editGrade      = ref({ name: "", level: 1, min_salary: "" as string | number, max_salary: "" as string | number });

// Add forms
const catForm   = ref({ name: "", description: "" });
const desigForm = ref({ title: "", description: "" });
const gradeForm = ref({ name: "", level: 1, min_salary: "", max_salary: "" });

// ── Org Structure (hierarchy) ─────────────────────────────────────────────
const orgStructure        = ref<OrgStructureData | null>(null);
const structureLoading    = ref(false);
const structureError      = ref("");

// Expanded state: which units/depts/bus are open
const expandedUnits = ref<Set<number>>(new Set());
const expandedDepts = ref<Set<number>>(new Set());
const expandedBUs   = ref<Set<number>>(new Set());

function toggleUnit(id: number) { expandedUnits.value.has(id) ? expandedUnits.value.delete(id) : expandedUnits.value.add(id); }
function toggleDept(id: number) { expandedDepts.value.has(id) ? expandedDepts.value.delete(id) : expandedDepts.value.add(id); }
function toggleBU(id: number)   { expandedBUs.value.has(id)   ? expandedBUs.value.delete(id)   : expandedBUs.value.add(id); }

// ── Functional Unit forms/edit ─────────────────────────────────────────────
const showUnitForm    = ref(false);
const unitForm        = ref({ name: "", code: "", description: "" });
const editingUnit     = ref<OrgFunctionalUnit | null>(null);
const unitSaving      = ref(false);

function openNewUnit()  { editingUnit.value = null; unitForm.value = { name: "", code: "", description: "" }; showUnitForm.value = true; }
function openEditUnit(u: OrgFunctionalUnit) { editingUnit.value = u; unitForm.value = { name: u.name, code: u.code ?? "", description: u.description ?? "" }; showUnitForm.value = true; }
function closeUnitForm() { showUnitForm.value = false; }

async function saveUnit(): Promise<void> {
  if (!unitForm.value.name.trim()) return;
  unitSaving.value = true;
  try {
    if (editingUnit.value) {
      await api("PUT", `/api/v1/org/functional-units/${editingUnit.value.id}`, { org: org(), body: unitForm.value });
    } else {
      await api("POST", "/api/v1/org/functional-units", { org: org(), body: unitForm.value });
    }
    showUnitForm.value = false;
    await loadStructure();
  } catch { /* silent */ } finally { unitSaving.value = false; }
}

async function deleteUnit(id: number): Promise<void> {
  if (!confirm("Delete this functional unit? Departments inside it will become unassigned.")) return;
  await api("DELETE", `/api/v1/org/functional-units/${id}`, { org: org() });
  await loadStructure();
}

// ── Department forms/edit ──────────────────────────────────────────────────
const showDeptForm    = ref(false);
const deptForm        = ref({ name: "", code: "", cost_center: "", description: "", functional_unit_id: null as number | null, lead_employee_id: null as number | null });
const editingDept     = ref<OrgDepartment | null>(null);
const deptSaving      = ref(false);
const functionalUnits = ref<OrgFunctionalUnit[]>([]);

function openNewDept(unitId?: number) {
  editingDept.value = null;
  deptForm.value = { name: "", code: "", cost_center: "", description: "", functional_unit_id: unitId ?? null, lead_employee_id: null };
  showDeptForm.value = true;
}
function openEditDept(d: OrgDepartment) {
  editingDept.value = d;
  deptForm.value = { name: d.name, code: d.code ?? "", cost_center: d.cost_center ?? "", description: d.description ?? "", functional_unit_id: d.functional_unit_id, lead_employee_id: d.lead_employee_id };
  showDeptForm.value = true;
}
function closeDeptForm() { showDeptForm.value = false; }

async function saveDept(): Promise<void> {
  if (!deptForm.value.name.trim()) return;
  deptSaving.value = true;
  try {
    if (editingDept.value) {
      await api("PUT", `/api/v1/org/departments/${editingDept.value.id}`, { org: org(), body: deptForm.value });
    } else {
      await api("POST", "/api/v1/org/departments", { org: org(), body: deptForm.value });
    }
    showDeptForm.value = false;
    await loadStructure();
  } catch { /* silent */ } finally { deptSaving.value = false; }
}

async function deleteDept(id: number): Promise<void> {
  if (!confirm("Delete this department?")) return;
  await api("DELETE", `/api/v1/org/departments/${id}`, { org: org() });
  await loadStructure();
}

// ── Business Unit forms/edit ──────────────────────────────────────────────
const showBUForm   = ref(false);
const buForm       = ref({ name: "", code: "", description: "", department_id: null as number | null, lead_employee_id: null as number | null });
const editingBU    = ref<OrgBusinessUnit | null>(null);
const buSaving     = ref(false);

function openNewBU(deptId: number) {
  editingBU.value = null;
  buForm.value = { name: "", code: "", description: "", department_id: deptId, lead_employee_id: null };
  showBUForm.value = true;
}
function openEditBU(bu: OrgBusinessUnit) {
  editingBU.value = bu;
  buForm.value = { name: bu.name, code: bu.code ?? "", description: bu.description ?? "", department_id: bu.department_id, lead_employee_id: bu.lead_employee_id };
  showBUForm.value = true;
}
function closeBUForm() { showBUForm.value = false; }

async function saveBU(): Promise<void> {
  if (!buForm.value.name.trim()) return;
  buSaving.value = true;
  try {
    if (editingBU.value) {
      await api("PUT", `/api/v1/org/business-units/${editingBU.value.id}`, { org: org(), body: buForm.value });
    } else {
      await api("POST", "/api/v1/org/business-units", { org: org(), body: buForm.value });
    }
    showBUForm.value = false;
    await loadStructure();
  } catch { /* silent */ } finally { buSaving.value = false; }
}

async function deleteBU(id: number): Promise<void> {
  if (!confirm("Delete this business unit?")) return;
  await api("DELETE", `/api/v1/org/business-units/${id}`, { org: org() });
  await loadStructure();
}

// ── Practice forms/edit ───────────────────────────────────────────────────
const showPracticeForm = ref(false);
const practiceForm     = ref({ name: "", code: "", description: "", business_unit_id: null as number | null, lead_employee_id: null as number | null });
const editingPractice  = ref<OrgPractice | null>(null);
const practiceSaving   = ref(false);

function openNewPractice(buId: number) {
  editingPractice.value = null;
  practiceForm.value = { name: "", code: "", description: "", business_unit_id: buId, lead_employee_id: null };
  showPracticeForm.value = true;
}
function openEditPractice(p: OrgPractice) {
  editingPractice.value = p;
  practiceForm.value = { name: p.name, code: p.code ?? "", description: p.description ?? "", business_unit_id: p.business_unit_id, lead_employee_id: p.lead_employee_id };
  showPracticeForm.value = true;
}
function closePracticeForm() { showPracticeForm.value = false; }

async function savePractice(): Promise<void> {
  if (!practiceForm.value.name.trim()) return;
  practiceSaving.value = true;
  try {
    if (editingPractice.value) {
      await api("PUT", `/api/v1/org/practices/${editingPractice.value.id}`, { org: org(), body: practiceForm.value });
    } else {
      await api("POST", "/api/v1/org/practices", { org: org(), body: practiceForm.value });
    }
    showPracticeForm.value = false;
    await loadStructure();
  } catch { /* silent */ } finally { practiceSaving.value = false; }
}

async function deletePractice(id: number): Promise<void> {
  if (!confirm("Delete this practice?")) return;
  await api("DELETE", `/api/v1/org/practices/${id}`, { org: org() });
  await loadStructure();
}

async function loadStructure(): Promise<void> {
  if (!org()) return;
  structureLoading.value = true; structureError.value = "";
  try {
    const res = await api<OrgStructureData>("GET", "/api/v1/org/structure", { org: org() });
    orgStructure.value = res;
    functionalUnits.value = res.functional_units ?? [];
  } catch (e) {
    structureError.value = e instanceof Error ? e.message : "Failed to load structure.";
  } finally {
    structureLoading.value = false;
  }
}

function org(): string | null { return currentOrgSlug.value; }

async function loadOrgs(): Promise<void> {
  try {
    const res = await api<{ data: OrgProfile[] }>("GET", "/api/v1/organizations");
    allOrgs.value = res.data;
    // Sync branding fields in auth store WITHOUT resetting the selected org
    res.data.forEach(o => {
      updateOrgBranding(o.id, { logo_url: o.logo_url, primary_color: o.primary_color });
    });
  } catch { /* non-critical */ }
}

async function load(): Promise<void> {
  if (!org()) { error.value = "Select an organisation first."; return; }
  loading.value = true; error.value = "";
  try {
    const [des, g, cat] = await Promise.all([
      api<{ data: Designation[] }>("GET", "/api/v1/org/designations",   { org: org() }),
      api<{ data: JobGrade[] }>("GET", "/api/v1/org/job-grades",        { org: org() }),
      api<{ data: JobCategory[] }>("GET", "/api/v1/org/job-categories", { org: org() }),
    ]);
    designations.value  = des.data;
    jobGrades.value     = g.data;
    jobCategories.value = cat.data;
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to load.";
  } finally {
    loading.value = false;
  }
}

// ── Job Categories ──────────────────────────────────────────────────────────
function toggleCat(id: number): void {
  if (selectedCatIds.value.has(id)) selectedCatIds.value.delete(id);
  else selectedCatIds.value.add(id);
}

async function addCategory(): Promise<void> {
  if (!catForm.value.name.trim()) return;
  saving.value = true; saveError.value = "";
  try {
    await api("POST", "/api/v1/org/job-categories", { org: org(), body: catForm.value });
    catForm.value = { name: "", description: "" };
    await load();
  } catch (e) { saveError.value = e instanceof Error ? e.message : "Failed to save."; }
  finally { saving.value = false; }
}

async function deleteCat(id: number): Promise<void> {
  if (!confirm("Delete this job category?")) return;
  await api("DELETE", `/api/v1/org/job-categories/${id}`, { org: org() });
  selectedCatIds.value.delete(id);
  await load();
}

function startEditCat(c: JobCategory): void {
  editCatId.value = c.id;
  editCat.value = { name: c.name, description: c.description ?? "" };
}

async function saveEditCat(): Promise<void> {
  saving.value = true; saveError.value = "";
  try {
    await api("PUT", `/api/v1/org/job-categories/${editCatId.value}`, { org: org(), body: editCat.value });
    editCatId.value = null;
    await load();
  } catch (e) { saveError.value = e instanceof Error ? e.message : "Failed."; }
  finally { saving.value = false; }
}

// ── Designations ─────────────────────────────────────────────────────────────
async function addDesig(): Promise<void> {
  if (!desigForm.value.title.trim()) return;
  saving.value = true; saveError.value = "";
  try {
    await api("POST", "/api/v1/org/designations", { org: org(), body: desigForm.value });
    desigForm.value = { title: "", description: "" };
    await load();
  } catch (e) { saveError.value = e instanceof Error ? e.message : "Failed to save."; }
  finally { saving.value = false; }
}

async function deleteDesig(id: number): Promise<void> {
  if (!confirm("Delete this designation?")) return;
  await api("DELETE", `/api/v1/org/designations/${id}`, { org: org() });
  await load();
}

function startEditDesig(d: Designation): void {
  editDesigId.value = d.id;
  editDesig.value = { title: d.title, description: d.description ?? "" };
}

async function saveEditDesig(): Promise<void> {
  saving.value = true; saveError.value = "";
  try {
    await api("PUT", `/api/v1/org/designations/${editDesigId.value}`, { org: org(), body: editDesig.value });
    editDesigId.value = null;
    await load();
  } catch (e) { saveError.value = e instanceof Error ? e.message : "Failed."; }
  finally { saving.value = false; }
}

// ── Job Grades ───────────────────────────────────────────────────────────────
async function addGrade(): Promise<void> {
  if (!gradeForm.value.name.trim()) return;
  saving.value = true; saveError.value = "";
  try {
    await api("POST", "/api/v1/org/job-grades", {
      org: org(),
      body: {
        ...gradeForm.value,
        min_salary: gradeForm.value.min_salary ? Number(gradeForm.value.min_salary) : null,
        max_salary: gradeForm.value.max_salary ? Number(gradeForm.value.max_salary) : null,
      },
    });
    gradeForm.value = { name: "", level: 1, min_salary: "", max_salary: "" };
    await load();
  } catch (e) { saveError.value = e instanceof Error ? e.message : "Failed to save."; }
  finally { saving.value = false; }
}

async function deleteGrade(id: number): Promise<void> {
  if (!confirm("Delete this job grade?")) return;
  await api("DELETE", `/api/v1/org/job-grades/${id}`, { org: org() });
  await load();
}

function startEditGrade(g: JobGrade): void {
  editGradeId.value = g.id;
  editGrade.value = { name: g.name, level: g.level, min_salary: g.min_salary ?? "", max_salary: g.max_salary ?? "" };
}

async function saveEditGrade(): Promise<void> {
  saving.value = true; saveError.value = "";
  try {
    await api("PUT", `/api/v1/org/job-grades/${editGradeId.value}`, {
      org: org(),
      body: { ...editGrade.value, min_salary: editGrade.value.min_salary !== "" ? Number(editGrade.value.min_salary) : null, max_salary: editGrade.value.max_salary !== "" ? Number(editGrade.value.max_salary) : null },
    });
    editGradeId.value = null;
    await load();
  } catch (e) { saveError.value = e instanceof Error ? e.message : "Failed."; }
  finally { saving.value = false; }
}

onMounted(async () => { await loadOrgs(); await load(); await loadCompliance(); await loadStructure(); });
watch(currentOrgSlug, async () => {
  // Reset all org-scoped data so stale data from the previous org is never shown
  complianceDocs.value = [];
  complianceServerSummary.value = null;
  complianceError.value = "";
  orgStructure.value = null;
  expandedUnits.value = new Set();
  expandedDepts.value = new Set();
  expandedBUs.value = new Set();
  // Close any open compliance panels that belong to the previous org
  showInfoPopup.value = false;
  infoDoc.value = null;
  showDateDrawer.value = false;
  editingDoc.value = null;
  showReminderDrawer.value = false;
  editingReminderDoc.value = null;
  saveSuccess.value = "";
  profileEdit.value = false;
  profileTab.value = "overview";
  await loadOrgs();
  await load();
  await loadCompliance();
  await loadStructure();
});
watch(tab, (newTab) => {
  if (newTab === "profile" && complianceDocs.value.length === 0) loadCompliance();
  if (newTab === "departments" && !orgStructure.value) loadStructure();
});
watch(profileTab, (newPTab) => { if (newPTab === "compliance" && complianceDocs.value.length === 0) loadCompliance(); });

// ── Compliance ────────────────────────────────────────────────────────────────
const complianceDocs             = ref<ComplianceDocumentRecord[]>([]);
const complianceLoading          = ref(false);
const complianceError            = ref("");
const complianceServerSummary    = ref<ComplianceSummary | null>(null);

const infoDoc          = ref<ComplianceDocumentRecord | null>(null);
const showInfoPopup    = ref(false);
const editingGuide     = ref(false);
const guideEditText    = ref("");
const savingGuide      = ref(false);
const guideError       = ref("");

const uploadingKey     = ref<string | null>(null);
const uploadFileInput  = ref<HTMLInputElement | null>(null);
const uploadTargetKey  = ref<string | null>(null);

const editingDoc       = ref<ComplianceDocumentRecord | null>(null);
const showDateDrawer   = ref(false);
const dateForm         = ref({ issue_date: "", expiry_date: "", anniversary_date: "", notes: "" });
const savingDate       = ref(false);
const dateError        = ref("");

const editingReminderDoc  = ref<ComplianceDocumentRecord | null>(null);
const showReminderDrawer  = ref(false);
const reminderForm        = ref({ reminder_days_before: 30, reminder_recipients: "", reminder_active: true });
const savingReminder      = ref(false);
const reminderError       = ref("");

const complianceSegments = computed<{ segment: string; docs: ComplianceDocumentRecord[] }[]>(() => {
  const map = new Map<string, ComplianceDocumentRecord[]>();
  for (const doc of complianceDocs.value) {
    const seg = doc.segment || "Other";
    if (!map.has(seg)) map.set(seg, []);
    map.get(seg)!.push(doc);
  }
  return Array.from(map.entries()).map(([segment, docs]) => ({ segment, docs }));
});

const complianceSummary = computed(() => {
  const s = complianceServerSummary.value;
  if (s) return s;
  const total        = complianceDocs.value.length;
  const not_uploaded = complianceDocs.value.filter((d) => !d.file_url).length;
  const valid        = complianceDocs.value.filter((d) => d.status === "Valid").length;
  const expiring     = complianceDocs.value.filter((d) => d.status.startsWith("Expiring")).length;
  const expired      = complianceDocs.value.filter((d) => d.status === "Expired").length;
  return { total, not_uploaded, valid, expiring, expired, under_review: 0, pending_renewal: 0 };
});

async function loadCompliance(): Promise<void> {
  complianceLoading.value = true;
  complianceError.value = "";
  try {
    const result = await api<ComplianceDocumentListData>("GET", "/api/v1/org/compliance", { org: org() });
    complianceDocs.value = result.data ?? [];
    complianceServerSummary.value = result.summary ?? null;
  } catch (e) {
    complianceError.value = e instanceof Error ? e.message : "Failed to load compliance documents.";
  } finally {
    complianceLoading.value = false;
  }
}

function openInfo(doc: ComplianceDocumentRecord): void {
  infoDoc.value = doc;
  editingGuide.value = false;
  guideEditText.value = doc.renewal_guide ?? "";
  guideError.value = "";
  showInfoPopup.value = true;
}

function closeInfo(): void {
  showInfoPopup.value = false;
  infoDoc.value = null;
  editingGuide.value = false;
}

function startEditGuide(): void {
  guideEditText.value = infoDoc.value?.renewal_guide ?? "";
  editingGuide.value = true;
}

async function saveGuide(): Promise<void> {
  if (!infoDoc.value) return;
  savingGuide.value = true;
  guideError.value = "";
  try {
    const updated = await api<ComplianceDocumentRecord>(
      "PUT", `/api/v1/org/compliance/${infoDoc.value.document_key}/guide`,
      { org: org(), body: { renewal_guide: guideEditText.value } },
    );
    infoDoc.value.renewal_guide = updated.renewal_guide;
    const idx = complianceDocs.value.findIndex((d) => d.document_key === infoDoc.value!.document_key);
    if (idx !== -1) complianceDocs.value[idx] = updated;
    editingGuide.value = false;
  } catch (e) {
    guideError.value = e instanceof Error ? e.message : "Failed to save guide.";
  } finally {
    savingGuide.value = false;
  }
}

function triggerUpload(doc: ComplianceDocumentRecord): void {
  uploadTargetKey.value = doc.document_key;
  uploadFileInput.value?.click();
}

async function onFileSelected(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  if (!file || !uploadTargetKey.value) return;
  uploadingKey.value = uploadTargetKey.value;
  const key = uploadTargetKey.value;
  try {
    const formData = new FormData();
    formData.append("file", file);
    const res = await fetch(`/api/v1/org/compliance/${key}/upload`, {
      method: "POST",
      headers: { Accept: "application/json", Authorization: `Bearer ${getToken() ?? ""}`, "X-Organization": org() ?? "" },
      body: formData,
    });
    if (!res.ok) {
      const d = await res.json().catch(() => ({ message: res.statusText })) as { message?: string };
      throw new Error(d.message ?? "Upload failed.");
    }
    const updated = await res.json() as ComplianceDocumentRecord;
    const idx = complianceDocs.value.findIndex((d) => d.document_key === key);
    if (idx !== -1) complianceDocs.value[idx] = updated;
  } catch (e) {
    alert(e instanceof Error ? e.message : "Upload failed.");
  } finally {
    uploadingKey.value = null;
    uploadTargetKey.value = null;
    input.value = "";
  }
}

async function removeFile(doc: ComplianceDocumentRecord): Promise<void> {
  if (!confirm(`Remove the uploaded file for "${doc.document_label}"?`)) return;
  try {
    const updated = await api<ComplianceDocumentRecord>(
      "DELETE", `/api/v1/org/compliance/${doc.document_key}/file`, { org: org() },
    );
    if (updated) {
      const idx = complianceDocs.value.findIndex((d) => d.document_key === doc.document_key);
      if (idx !== -1) complianceDocs.value[idx] = updated;
    }
  } catch (e) {
    alert(e instanceof Error ? e.message : "Failed to remove file.");
  }
}

function openDateDrawer(doc: ComplianceDocumentRecord): void {
  editingDoc.value = doc;
  dateForm.value = { issue_date: doc.issue_date ?? "", expiry_date: doc.expiry_date ?? "", anniversary_date: doc.anniversary_date ?? "", notes: doc.notes ?? "" };
  dateError.value = "";
  showDateDrawer.value = true;
}

function closeDateDrawer(): void { showDateDrawer.value = false; editingDoc.value = null; }

async function saveDates(): Promise<void> {
  if (!editingDoc.value) return;
  savingDate.value = true;
  dateError.value = "";
  try {
    const updated = await api<ComplianceDocumentRecord>(
      "PUT", `/api/v1/org/compliance/${editingDoc.value.document_key}`,
      { org: org(), body: { issue_date: dateForm.value.issue_date || null, expiry_date: dateForm.value.expiry_date || null, anniversary_date: dateForm.value.anniversary_date || null, notes: dateForm.value.notes } },
    );
    const idx = complianceDocs.value.findIndex((d) => d.document_key === updated.document_key);
    if (idx !== -1) complianceDocs.value[idx] = updated;
    closeDateDrawer();
  } catch (e) {
    dateError.value = e instanceof Error ? e.message : "Failed to save dates.";
  } finally {
    savingDate.value = false;
  }
}

function openReminderDrawer(doc: ComplianceDocumentRecord): void {
  editingReminderDoc.value = doc;
  reminderForm.value = { reminder_days_before: doc.reminder_days_before ?? 30, reminder_recipients: doc.reminder_recipients ?? "", reminder_active: doc.reminder_active };
  reminderError.value = "";
  showReminderDrawer.value = true;
}

function closeReminderDrawer(): void { showReminderDrawer.value = false; editingReminderDoc.value = null; }

async function saveReminder(): Promise<void> {
  if (!editingReminderDoc.value) return;
  savingReminder.value = true;
  reminderError.value = "";
  try {
    const updated = await api<ComplianceDocumentRecord>(
      "PUT", `/api/v1/org/compliance/${editingReminderDoc.value.document_key}/reminder`,
      { org: org(), body: { reminder_days_before: reminderForm.value.reminder_days_before, reminder_recipients: reminderForm.value.reminder_recipients, reminder_active: reminderForm.value.reminder_active } },
    );
    const idx = complianceDocs.value.findIndex((d) => d.document_key === updated.document_key);
    if (idx !== -1) complianceDocs.value[idx] = updated;
    closeReminderDrawer();
  } catch (e) {
    reminderError.value = e instanceof Error ? e.message : "Failed to save reminder settings.";
  } finally {
    savingReminder.value = false;
  }
}
</script>

<template>
  <div class="view">
    <div class="view-header">
      <div>
        <h2>Organisation</h2>
        <p class="ov-subtitle" v-if="authStore.currentOrg">{{ authStore.currentOrg.name }}</p>
      </div>
      <div style="display:flex;gap:10px;align-items:center">
        <button class="btn btn-primary btn-sm" @click="openNewOrg">+ Register New Organisation</button>
        <router-link :to="{ name: 'hr-dashboard' }" class="btn btn-secondary btn-sm">← Dashboard</router-link>
      </div>
    </div>

    <p v-if="loading" class="status">Loading…</p>
    <p v-if="error"   class="error">{{ error }}</p>
    <p v-if="saveError" class="error">{{ saveError }}</p>
    <p v-if="saveSuccess" class="ov-success">{{ saveSuccess }}</p>

    <!-- Tab bar -->
    <div class="tabs" style="margin-bottom:20px">
      <button :class="['tab', { active: tab === 'profile' }]"       @click="tab = 'profile'">Profile</button>
      <button :class="['tab', { active: tab === 'departments' }]"   @click="tab = 'departments'">Departments</button>
      <button :class="['tab', { active: tab === 'designations' }]"  @click="tab = 'designations'">Designations</button>
      <button :class="['tab', { active: tab === 'grades' }]"        @click="tab = 'grades'">Job Grades</button>
      <button :class="['tab', { active: tab === 'categories' }]"    @click="tab = 'categories'">Job Categories</button>
      <button :class="['tab', { active: tab === 'asset-categories' }]" @click="tab = 'asset-categories'; loadAssetCategories()">Asset Categories</button>

      <button class="tab" @click="$router.push('/org/benefit-plans')">Benefit Plans</button>
      <button class="tab" @click="$router.push('/leave/admin')">Leave Admin</button>
      <button class="tab" @click="$router.push('/org/payroll-config')">Payroll Config</button>
    </div>

    <!-- ── PROFILE ──────────────────────────────────────────────────────────── -->
    <template v-if="tab === 'profile'">
      <!-- Profile sub-tab bar -->
      <div class="tabs ov-subtabs" style="margin-bottom:20px">
        <button :class="['tab', { active: profileTab === 'overview' }]" @click="profileTab = 'overview'">Overview</button>
        <button :class="['tab', { active: profileTab === 'compliance' }]" @click="profileTab = 'compliance'">🛡️ Compliance</button>
      </div>

      <div class="card ov-profile-card" v-if="profileTab === 'overview'">
        <div class="ov-profile-header">
          <div>
            <h3 class="card-title" style="margin-bottom:2px">Organisation Profile</h3>
            <p class="ov-meta">Slug: <code>{{ currentOrgProfile?.slug ?? '—' }}</code></p>
          </div>
          <button v-if="!profileEdit" class="btn btn-secondary btn-sm" @click="startProfileEdit">Edit Profile</button>
          <div v-else style="display:flex;gap:8px">
            <button class="btn btn-primary btn-sm" :disabled="saving" @click="saveProfile">Save</button>
            <button class="btn btn-ghost btn-sm" @click="profileEdit = false">Cancel</button>
          </div>
        </div>

        <!-- View mode -->
        <div v-if="!profileEdit">
          <!-- Branding section -->
          <div class="ov-branding-row">
            <!-- Logo upload -->
            <div class="ov-branding-block">
              <span class="ov-field-label">Organisation Logo</span>
              <div class="ov-logo-area">
                <div class="ov-logo-preview">
                  <img v-if="currentOrgProfile?.logo_url" :src="currentOrgProfile.logo_url" alt="Logo" class="ov-logo-img" />
                  <span v-else class="ov-logo-placeholder">No logo</span>
                </div>
                <div class="ov-logo-actions">
                  <label class="btn btn-secondary btn-sm ov-upload-btn" :class="{ 'ov-uploading': logoUploading }">
                    <svg viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px;margin-right:4px"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM6.293 6.707a1 1 0 010-1.414l3-3a1 1 0 011.414 0l3 3a1 1 0 01-1.414 1.414L11 5.414V13a1 1 0 11-2 0V5.414L7.707 6.707a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
                    {{ logoUploading ? 'Uploading…' : 'Upload Logo' }}
                    <input ref="logoFileInput" type="file" accept="image/png,image/jpeg,image/jpg,image/svg+xml,image/webp" style="display:none" :disabled="logoUploading" @change="uploadLogo" />
                  </label>
                  <p v-if="logoError" class="ov-logo-error">{{ logoError }}</p>
                  <p class="ov-logo-hint">PNG, JPG, SVG or WebP · max 2 MB</p>
                </div>
              </div>
            </div>

            <!-- Primary colour -->
            <div class="ov-branding-block">
              <span class="ov-field-label">Primary Colour</span>
              <div class="ov-color-display">
                <span class="ov-color-swatch" :style="{ background: currentOrgProfile?.primary_color || '#E5E7EB' }"></span>
                <span class="ov-color-value">{{ currentOrgProfile?.primary_color || 'Not set' }}</span>
                <span v-if="!currentOrgProfile?.primary_color" class="ov-color-hint">(Click Edit Profile to set)</span>
              </div>
            </div>
          </div>

          <div class="ov-profile-grid">
            <div class="ov-profile-field"><span class="ov-field-label">Display Name</span><span>{{ currentOrgProfile?.name ?? '—' }}</span></div>
            <div class="ov-profile-field"><span class="ov-field-label">Legal Name</span><span>{{ currentOrgProfile?.legal_name ?? '—' }}</span></div>
            <div class="ov-profile-field"><span class="ov-field-label">Industry</span><span>{{ currentOrgProfile?.industry ?? '—' }}</span></div>
            <div class="ov-profile-field"><span class="ov-field-label">Country</span><span>{{ currentOrgProfile?.country ?? '—' }}</span></div>
            <div class="ov-profile-field"><span class="ov-field-label">Currency</span><span>{{ currentOrgProfile?.currency ?? '—' }}</span></div>
            <div class="ov-profile-field"><span class="ov-field-label">Primary Email</span><span>{{ currentOrgProfile?.primary_email ?? '—' }}</span></div>
            <div class="ov-profile-field"><span class="ov-field-label">Primary Phone</span><span>{{ currentOrgProfile?.primary_phone ?? '—' }}</span></div>
            <div class="ov-profile-field ov-profile-field--full"><span class="ov-field-label">Address</span><span>{{ currentOrgProfile?.address ?? '—' }}</span></div>
            <div class="ov-profile-field">
              <span class="ov-field-label">Status</span>
              <span :class="currentOrgProfile?.is_active ? 'badge badge-green' : 'badge badge-gray'">
                {{ currentOrgProfile?.is_active ? 'Active' : 'Inactive' }}
              </span>
            </div>
          </div>
        </div>

        <!-- Edit mode -->
        <div v-else class="ov-edit-grid">
          <div class="form-field">
            <label class="ov-label">Display Name <span class="ov-req">*</span></label>
            <input v-model="profileForm.name" type="text" class="input" placeholder="Organisation display name" />
          </div>
          <div class="form-field">
            <label class="ov-label">Legal / Registered Name</label>
            <input v-model="profileForm.legal_name" type="text" class="input" placeholder="Full legal name" />
          </div>
          <div class="form-field">
            <label class="ov-label">Industry</label>
            <select v-model="profileForm.industry" class="input">
              <option value="">— Select —</option>
              <option>Technology</option><option>Financial Services</option><option>Healthcare</option>
              <option>Education</option><option>Manufacturing</option><option>Retail</option>
              <option>Logistics & Transport</option><option>Consulting</option><option>Media & Communications</option>
              <option>Government & Public Sector</option><option>NGO / Non-Profit</option><option>Other</option>
            </select>
          </div>
          <div class="form-field">
            <label class="ov-label">Country (ISO 2-letter)</label>
            <input v-model="profileForm.country" type="text" class="input" maxlength="2" placeholder="KE" />
          </div>
          <div class="form-field">
            <label class="ov-label">Currency (ISO 3-letter)</label>
            <input v-model="profileForm.currency" type="text" class="input" maxlength="3" placeholder="KES" />
          </div>
          <div class="form-field">
            <label class="ov-label">Primary Email</label>
            <input v-model="profileForm.primary_email" type="email" class="input" placeholder="hr@company.co.ke" />
          </div>
          <div class="form-field">
            <label class="ov-label">Primary Phone</label>
            <input v-model="profileForm.primary_phone" type="tel" class="input" placeholder="+254 700 000 000" />
          </div>
          <div class="form-field ov-edit-field--full">
            <label class="ov-label">Address</label>
            <textarea v-model="profileForm.address" class="input ov-textarea" rows="2" placeholder="Postal address / physical location" />
          </div>
          <div class="form-field">
            <label class="ov-label">Primary Colour</label>
            <div class="ov-color-picker-row">
              <input v-model="profileForm.primary_color" type="color" class="ov-color-input" />
              <input v-model="profileForm.primary_color" type="text" class="input ov-color-text" maxlength="7" placeholder="#004C97" />
              <span class="ov-color-hint-sm">Used as the accent colour across the app for this organisation.</span>
            </div>
          </div>
        </div>
      </div>

      <!-- All accessible organisations list -->
      <div v-if="profileTab === 'overview'" class="card" style="margin-top:16px">
        <h3 class="card-title">All Accessible Organisations</h3>
        <table>
          <thead>
            <tr>
              <th>Name</th><th>Slug</th><th>Industry</th><th>Country</th><th>Status</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="o in allOrgs" :key="o.id" :class="{ 'ov-row-active': o.slug === currentOrgSlug }">
              <td>
                <router-link :to="{ name: 'org-profile', params: { slug: o.slug } }" class="ov-org-link">
                  <strong>{{ o.name }}</strong>
                </router-link>
              </td>
              <td><code>{{ o.slug }}</code></td>
              <td>{{ o.industry ?? '—' }}</td>
              <td>{{ o.country }}</td>
              <td><span :class="o.is_active ? 'badge badge-green' : 'badge badge-gray'">{{ o.is_active ? 'Active' : 'Inactive' }}</span></td>
            </tr>
            <tr v-if="!allOrgs.length"><td colspan="5" class="status">No organisations found.</td></tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- ── ASSET CATEGORIES ──────────────────────────────────────────────────── -->
    <template v-if="tab === 'asset-categories'">
      <div class="card" style="margin-bottom:16px">
        <h3 class="card-title">Add Asset Category</h3>
        <p class="ov-hint">
          What this organisation owns. A category belongs to one class — "SSL
          certificate" is not a kind of laptop, and offering it when somebody
          registers a monitor is how a register gets filled in wrongly.
        </p>
        <div class="form-row">
          <select v-model="acForm.asset_class" class="input">
            <option v-for="c in ASSET_CLASSES" :key="c.key" :value="c.key">{{ c.label }}</option>
          </select>
          <input v-model="acForm.name" type="text" placeholder="Category name" class="input" />
          <input v-model="acForm.description" type="text" placeholder="What belongs here (optional)" class="input" />
          <button class="btn btn-primary btn-sm" :disabled="saving || !acForm.name.trim()" @click="addAssetCategory">Add</button>
        </div>
        <p v-if="acError" class="ov-hint" style="color:var(--color-danger-text)">{{ acError }}</p>
      </div>

      <div v-for="cls in ASSET_CLASSES" :key="cls.key" class="card" style="margin-bottom:16px">
        <h3 class="card-title">{{ cls.label }}</h3>
        <p v-if="!assetCategoriesIn(cls.key).length" class="ov-hint">Nothing configured for this class.</p>
        <table v-else class="ov-cat-table">
          <thead>
            <tr><th>Category</th><th>What belongs here</th><th>In the picker</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="c in assetCategoriesIn(cls.key)" :key="c.id" :style="c.is_active ? '' : 'opacity:.55'">
              <td>{{ c.name }}</td>
              <td class="ov-cat-desc">{{ c.description ?? '—' }}</td>
              <td>
                <!-- Switched off rather than deleted, so assets already filed
                     under it still read correctly. -->
                <label class="ov-hint" style="cursor:pointer">
                  <input type="checkbox" :checked="c.is_active" @change="toggleAssetCategory(c)" />
                  {{ c.is_active ? 'shown' : 'hidden' }}
                </label>
              </td>
              <td class="ov-cat-actions">
                <button class="btn btn-ghost btn-sm" @click="removeAssetCategory(c)">Remove</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- ── JOB CATEGORIES ────────────────────────────────────────────────────── -->
    <template v-if="tab === 'categories'">
      <div class="card" style="margin-bottom:16px">
        <h3 class="card-title">Add Job Category</h3>
        <div class="form-row">
          <input v-model="catForm.name" type="text" placeholder="Category name" class="input" />
          <input v-model="catForm.description" type="text" placeholder="Description (optional)" class="input" />
          <button class="btn btn-primary btn-sm" :disabled="saving" @click="addCategory">Add</button>
        </div>
      </div>
      <div class="card">
        <table class="ov-cat-table">
          <thead>
            <tr>
              <th class="ov-cat-check"></th>
              <th>Job Category</th>
              <th>Description</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <template v-for="c in jobCategories" :key="c.id">
              <!-- View row -->
              <tr v-if="editCatId !== c.id" :class="{ 'ov-cat-selected': selectedCatIds.has(c.id) }">
                <td class="ov-cat-check">
                  <input type="checkbox" :checked="selectedCatIds.has(c.id)" @change="toggleCat(c.id)" class="ov-checkbox" />
                </td>
                <td>{{ c.name }}</td>
                <td class="ov-cat-desc">{{ c.description ?? '—' }}</td>
                <td class="ov-cat-actions">
                  <button class="ov-icon-btn" title="Edit" @click="startEditCat(c)">✏️</button>
                  <button class="ov-icon-btn ov-icon-btn--danger" title="Delete" @click="deleteCat(c.id)">🗑</button>
                </td>
              </tr>
              <!-- Edit row -->
              <tr v-else class="ov-cat-editing">
                <td class="ov-cat-check"></td>
                <td><input v-model="editCat.name" type="text" class="input" /></td>
                <td><input v-model="editCat.description" type="text" class="input" placeholder="Description" /></td>
                <td class="ov-cat-actions">
                  <button class="btn btn-primary btn-sm" :disabled="saving" @click="saveEditCat">Save</button>
                  <button class="btn btn-ghost btn-sm" @click="editCatId = null">Cancel</button>
                </td>
              </tr>
            </template>
            <tr v-if="!jobCategories.length">
              <td colspan="4" class="status">No job categories yet.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- ── DEPARTMENTS (Hierarchy Tree) ─────────────────────────────────────── -->
    <template v-if="tab === 'departments'">
      <!-- Toolbar -->
      <div class="hs-toolbar">
        <div>
          <h3 class="hs-toolbar-title">Organisation Structure</h3>
          <p class="hs-toolbar-sub">Functional Units → Departments → Business Units → Practices</p>
        </div>
        <button class="btn btn-primary btn-sm" @click="openNewUnit">+ Functional Unit</button>
      </div>

      <div v-if="structureLoading" class="org-loading"><span class="org-loading-spinner"></span><span>Loading structure…</span></div>
      <p v-if="structureError" class="error">{{ structureError }}</p>

      <div v-if="orgStructure" class="hs-tree">
        <!-- Functional Units -->
        <div v-for="unit in orgStructure.functional_units" :key="unit.id" class="hs-unit">
          <div class="hs-unit-header" @click="toggleUnit(unit.id)">
            <span class="hs-chevron" :class="{ open: expandedUnits.has(unit.id) }">▶</span>
            <span class="hs-unit-icon">🏢</span>
            <div class="hs-node-info">
              <span class="hs-node-name">{{ unit.name }}</span>
              <span v-if="unit.code" class="hs-node-code">{{ unit.code }}</span>
              <span class="hs-node-count">{{ unit.departments.length }} dept{{ unit.departments.length !== 1 ? 's' : '' }}</span>
            </div>
            <div class="hs-node-actions" @click.stop>
              <button class="hs-act-btn" title="Add department" @click="openNewDept(unit.id)">+ Dept</button>
              <button class="hs-act-btn" title="Edit" @click="openEditUnit(unit)">✏</button>
              <button class="hs-act-btn hs-act-danger" title="Delete" @click="deleteUnit(unit.id)">🗑</button>
            </div>
          </div>

          <div v-if="expandedUnits.has(unit.id)" class="hs-children">
            <!-- Departments inside this unit -->
            <div v-for="dept in unit.departments" :key="dept.id" class="hs-dept">
              <div class="hs-dept-header" @click="toggleDept(dept.id)">
                <span class="hs-chevron" :class="{ open: expandedDepts.has(dept.id) }">▶</span>
                <span class="hs-dept-icon">📁</span>
                <div class="hs-node-info">
                  <span class="hs-node-name">{{ dept.name }}</span>
                  <span v-if="dept.code" class="hs-node-code">{{ dept.code }}</span>
                  <span v-if="dept.lead" class="hs-node-lead">Lead: {{ dept.lead.first_name }} {{ dept.lead.last_name }}</span>
                  <span class="hs-node-count">{{ dept.headcount }} staff · {{ dept.business_units.length }} BU{{ dept.business_units.length !== 1 ? 's' : '' }}</span>
                </div>
                <div class="hs-node-actions" @click.stop>
                  <button class="hs-act-btn" title="Add business unit" @click="openNewBU(dept.id)">+ BU</button>
                  <button class="hs-act-btn" title="Edit" @click="openEditDept(dept)">✏</button>
                  <button class="hs-act-btn hs-act-danger" title="Delete" @click="deleteDept(dept.id)">🗑</button>
                </div>
              </div>

              <div v-if="expandedDepts.has(dept.id)" class="hs-children">
                <!-- Business Units -->
                <div v-for="bu in dept.business_units" :key="bu.id" class="hs-bu">
                  <div class="hs-bu-header" @click="toggleBU(bu.id)">
                    <span class="hs-chevron" :class="{ open: expandedBUs.has(bu.id) }">▶</span>
                    <span class="hs-bu-icon">🔷</span>
                    <div class="hs-node-info">
                      <span class="hs-node-name">{{ bu.name }}</span>
                      <span v-if="bu.code" class="hs-node-code">{{ bu.code }}</span>
                      <span v-if="bu.lead" class="hs-node-lead">Lead: {{ bu.lead.first_name }} {{ bu.lead.last_name }}</span>
                      <span class="hs-node-count">{{ bu.practices.length }} practice{{ bu.practices.length !== 1 ? 's' : '' }}</span>
                    </div>
                    <div class="hs-node-actions" @click.stop>
                      <button class="hs-act-btn" title="Add practice" @click="openNewPractice(bu.id)">+ Practice</button>
                      <button class="hs-act-btn" title="Edit" @click="openEditBU(bu)">✏</button>
                      <button class="hs-act-btn hs-act-danger" title="Delete" @click="deleteBU(bu.id)">🗑</button>
                    </div>
                  </div>

                  <div v-if="expandedBUs.has(bu.id)" class="hs-children">
                    <!-- Practices -->
                    <div v-for="p in bu.practices" :key="p.id" class="hs-practice">
                      <span class="hs-practice-icon">⬡</span>
                      <div class="hs-node-info">
                        <span class="hs-node-name">{{ p.name }}</span>
                        <span v-if="p.code" class="hs-node-code">{{ p.code }}</span>
                        <span v-if="p.lead" class="hs-node-lead">Lead: {{ p.lead.first_name }} {{ p.lead.last_name }}</span>
                      </div>
                      <div class="hs-node-actions">
                        <button class="hs-act-btn" title="Edit" @click="openEditPractice(p)">✏</button>
                        <button class="hs-act-btn hs-act-danger" title="Delete" @click="deletePractice(p.id)">🗑</button>
                      </div>
                    </div>
                    <div v-if="!bu.practices.length" class="hs-empty">No practices yet. <button class="hs-inline-add" @click="openNewPractice(bu.id)">+ Add practice</button></div>
                  </div>
                </div>
                <div v-if="!dept.business_units.length" class="hs-empty">No business units yet. <button class="hs-inline-add" @click="openNewBU(dept.id)">+ Add business unit</button></div>
              </div>
            </div>
            <div v-if="!unit.departments.length" class="hs-empty">No departments yet. <button class="hs-inline-add" @click="openNewDept(unit.id)">+ Add department</button></div>
          </div>
        </div>

        <!-- Unassigned Departments -->
        <div v-if="orgStructure.unassigned_departments.length" class="hs-unit hs-unit-unassigned">
          <div class="hs-unit-header" @click="toggleUnit(-1)">
            <span class="hs-chevron" :class="{ open: expandedUnits.has(-1) }">▶</span>
            <span class="hs-unit-icon">📂</span>
            <div class="hs-node-info">
              <span class="hs-node-name">Unassigned Departments</span>
              <span class="hs-node-count">{{ orgStructure.unassigned_departments.length }} dept{{ orgStructure.unassigned_departments.length !== 1 ? 's' : '' }}</span>
            </div>
            <div class="hs-node-actions" @click.stop>
              <button class="hs-act-btn" @click="openNewDept()">+ Dept</button>
            </div>
          </div>
          <div v-if="expandedUnits.has(-1)" class="hs-children">
            <div v-for="dept in orgStructure.unassigned_departments" :key="dept.id" class="hs-dept">
              <div class="hs-dept-header" @click="toggleDept(dept.id)">
                <span class="hs-chevron" :class="{ open: expandedDepts.has(dept.id) }">▶</span>
                <span class="hs-dept-icon">📁</span>
                <div class="hs-node-info">
                  <span class="hs-node-name">{{ dept.name }}</span>
                  <span v-if="dept.lead" class="hs-node-lead">Lead: {{ dept.lead.first_name }} {{ dept.lead.last_name }}</span>
                  <span class="hs-node-count">{{ dept.headcount }} staff</span>
                </div>
                <div class="hs-node-actions" @click.stop>
                  <button class="hs-act-btn" @click="openNewBU(dept.id)">+ BU</button>
                  <button class="hs-act-btn" @click="openEditDept(dept)">✏</button>
                  <button class="hs-act-btn hs-act-danger" @click="deleteDept(dept.id)">🗑</button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div v-if="!orgStructure.functional_units.length && !orgStructure.unassigned_departments.length" class="hs-empty-state">
          <p>No structure defined yet.</p>
          <button class="btn btn-primary btn-sm" @click="openNewUnit">Create your first Functional Unit</button>
        </div>
      </div>

      <!-- ── FUNCTIONAL UNIT modal ─────────────────────────────── -->
      <Teleport to="body">
        <div v-if="showUnitForm" class="comp-overlay" @click.self="closeUnitForm">
          <div class="comp-drawer" role="dialog">
            <div class="comp-drawer-header">
              <h3>{{ editingUnit ? 'Edit Functional Unit' : 'New Functional Unit' }}</h3>
              <button class="comp-popup-close" @click="closeUnitForm">✕</button>
            </div>
            <div class="comp-drawer-body">
              <label class="comp-field-label">Name <span class="ov-req">*</span></label>
              <input v-model="unitForm.name" type="text" class="comp-field-input" placeholder="e.g. Finance" />
              <label class="comp-field-label">Code</label>
              <input v-model="unitForm.code" type="text" class="comp-field-input" placeholder="e.g. FIN" maxlength="20" />
              <label class="comp-field-label">Description</label>
              <textarea v-model="unitForm.description" class="comp-field-input" rows="2"></textarea>
              <div class="comp-drawer-actions">
                <button class="btn btn-primary btn-sm" :disabled="unitSaving" @click="saveUnit">{{ unitSaving ? 'Saving…' : 'Save' }}</button>
                <button class="btn btn-ghost btn-sm" @click="closeUnitForm">Cancel</button>
              </div>
            </div>
          </div>
        </div>
      </Teleport>

      <!-- ── DEPARTMENT modal ──────────────────────────────────── -->
      <Teleport to="body">
        <div v-if="showDeptForm" class="comp-overlay" @click.self="closeDeptForm">
          <div class="comp-drawer" style="max-width:500px" role="dialog">
            <div class="comp-drawer-header">
              <h3>{{ editingDept ? 'Edit Department' : 'New Department' }}</h3>
              <button class="comp-popup-close" @click="closeDeptForm">✕</button>
            </div>
            <div class="comp-drawer-body">
              <label class="comp-field-label">Name <span class="ov-req">*</span></label>
              <input v-model="deptForm.name" type="text" class="comp-field-input" placeholder="e.g. Financial Reporting" />
              <label class="comp-field-label">Code</label>
              <input v-model="deptForm.code" type="text" class="comp-field-input" placeholder="e.g. FIN-REP" maxlength="20" />
              <label class="comp-field-label">Cost Centre</label>
              <input v-model="deptForm.cost_center" type="text" class="comp-field-input" placeholder="e.g. CC-1001" />
              <label class="comp-field-label">Functional Unit</label>
              <select v-model="deptForm.functional_unit_id" class="comp-field-input">
                <option :value="null">— Unassigned —</option>
                <option v-for="u in functionalUnits" :key="u.id" :value="u.id">{{ u.name }}</option>
              </select>
              <label class="comp-field-label">Department / Practice Lead</label>
              <input v-model.number="deptForm.lead_employee_id" type="number" class="comp-field-input" placeholder="Employee ID (optional)" />
              <small class="comp-field-hint">Enter the Employee ID of the lead. Employee search coming soon.</small>
              <label class="comp-field-label">Description</label>
              <textarea v-model="deptForm.description" class="comp-field-input" rows="2"></textarea>
              <div class="comp-drawer-actions">
                <button class="btn btn-primary btn-sm" :disabled="deptSaving" @click="saveDept">{{ deptSaving ? 'Saving…' : 'Save' }}</button>
                <button class="btn btn-ghost btn-sm" @click="closeDeptForm">Cancel</button>
              </div>
            </div>
          </div>
        </div>
      </Teleport>

      <!-- ── BUSINESS UNIT modal ───────────────────────────────── -->
      <Teleport to="body">
        <div v-if="showBUForm" class="comp-overlay" @click.self="closeBUForm">
          <div class="comp-drawer" style="max-width:500px" role="dialog">
            <div class="comp-drawer-header">
              <h3>{{ editingBU ? 'Edit Business Unit' : 'New Business Unit' }}</h3>
              <button class="comp-popup-close" @click="closeBUForm">✕</button>
            </div>
            <div class="comp-drawer-body">
              <label class="comp-field-label">Name <span class="ov-req">*</span></label>
              <input v-model="buForm.name" type="text" class="comp-field-input" placeholder="e.g. Retail Banking" />
              <label class="comp-field-label">Code</label>
              <input v-model="buForm.code" type="text" class="comp-field-input" maxlength="20" />
              <label class="comp-field-label">Business Unit Lead</label>
              <input v-model.number="buForm.lead_employee_id" type="number" class="comp-field-input" placeholder="Employee ID (optional)" />
              <label class="comp-field-label">Description</label>
              <textarea v-model="buForm.description" class="comp-field-input" rows="2"></textarea>
              <div class="comp-drawer-actions">
                <button class="btn btn-primary btn-sm" :disabled="buSaving" @click="saveBU">{{ buSaving ? 'Saving…' : 'Save' }}</button>
                <button class="btn btn-ghost btn-sm" @click="closeBUForm">Cancel</button>
              </div>
            </div>
          </div>
        </div>
      </Teleport>

      <!-- ── PRACTICE modal ────────────────────────────────────── -->
      <Teleport to="body">
        <div v-if="showPracticeForm" class="comp-overlay" @click.self="closePracticeForm">
          <div class="comp-drawer" style="max-width:500px" role="dialog">
            <div class="comp-drawer-header">
              <h3>{{ editingPractice ? 'Edit Practice' : 'New Practice' }}</h3>
              <button class="comp-popup-close" @click="closePracticeForm">✕</button>
            </div>
            <div class="comp-drawer-body">
              <label class="comp-field-label">Name <span class="ov-req">*</span></label>
              <input v-model="practiceForm.name" type="text" class="comp-field-input" placeholder="e.g. Front-End Engineering" />
              <label class="comp-field-label">Code</label>
              <input v-model="practiceForm.code" type="text" class="comp-field-input" maxlength="20" />
              <label class="comp-field-label">Practice Lead</label>
              <input v-model.number="practiceForm.lead_employee_id" type="number" class="comp-field-input" placeholder="Employee ID (optional)" />
              <label class="comp-field-label">Description</label>
              <textarea v-model="practiceForm.description" class="comp-field-input" rows="2"></textarea>
              <div class="comp-drawer-actions">
                <button class="btn btn-primary btn-sm" :disabled="practiceSaving" @click="savePractice">{{ practiceSaving ? 'Saving…' : 'Save' }}</button>
                <button class="btn btn-ghost btn-sm" @click="closePracticeForm">Cancel</button>
              </div>
            </div>
          </div>
        </div>
      </Teleport>
    </template>

    <!-- ── DESIGNATIONS ─────────────────────────────────────────────────────── -->
    <template v-if="tab === 'designations'">
      <div class="card" style="margin-bottom:16px">
        <h3 class="card-title">Add Designation</h3>
        <div class="form-row">
          <input v-model="desigForm.title" type="text" placeholder="Job title" class="input" />
          <input v-model="desigForm.description" type="text" placeholder="Description (optional)" class="input" />
          <button class="btn btn-primary btn-sm" :disabled="saving" @click="addDesig">Add</button>
        </div>
      </div>
      <div class="card">
        <table>
          <thead><tr><th>Title</th><th>Description</th><th></th></tr></thead>
          <tbody>
            <template v-for="d in designations" :key="d.id">
              <tr v-if="editDesigId !== d.id">
                <td>{{ d.title }}</td>
                <td>{{ d.description ?? "—" }}</td>
                <td style="text-align:right">
                  <button class="btn btn-ghost btn-sm" style="margin-right:4px" @click="startEditDesig(d)">Edit</button>
                  <button class="btn btn-sm" style="color:var(--red)" @click="deleteDesig(d.id)">Remove</button>
                </td>
              </tr>
              <tr v-else>
                <td><input v-model="editDesig.title" type="text" class="input" placeholder="Title" /></td>
                <td><input v-model="editDesig.description" type="text" class="input" placeholder="Description" /></td>
                <td style="text-align:right">
                  <button class="btn btn-primary btn-sm" style="margin-right:4px" :disabled="saving" @click="saveEditDesig">Save</button>
                  <button class="btn btn-ghost btn-sm" @click="editDesigId=null">Cancel</button>
                </td>
              </tr>
            </template>
            <tr v-if="!designations.length"><td colspan="3" class="status">No designations yet.</td></tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- ── JOB GRADES ────────────────────────────────────────────────────────── -->
    <template v-if="tab === 'grades'">
      <div class="card" style="margin-bottom:16px">
        <h3 class="card-title">Add Job Grade</h3>
        <div class="form-row">
          <input v-model="gradeForm.name" type="text" placeholder="Grade name (e.g. G3)" class="input" />
          <input v-model.number="gradeForm.level" type="number" min="1" placeholder="Level" class="input" style="max-width:80px" />
          <input v-model="gradeForm.min_salary" type="number" placeholder="Min salary" class="input" />
          <input v-model="gradeForm.max_salary" type="number" placeholder="Max salary" class="input" />
          <button class="btn btn-primary btn-sm" :disabled="saving" @click="addGrade">Add</button>
        </div>
      </div>
      <div class="card">
        <table>
          <thead><tr><th>Grade</th><th>Level</th><th>Min Salary</th><th>Max Salary</th><th></th></tr></thead>
          <tbody>
            <template v-for="g in jobGrades" :key="g.id">
              <tr v-if="editGradeId !== g.id">
                <td>{{ g.name }}</td>
                <td>{{ g.level }}</td>
                <td>{{ g.min_salary != null ? g.min_salary.toLocaleString() : "—" }}</td>
                <td>{{ g.max_salary != null ? g.max_salary.toLocaleString() : "—" }}</td>
                <td style="text-align:right">
                  <button class="btn btn-ghost btn-sm" style="margin-right:4px" @click="startEditGrade(g)">Edit</button>
                  <button class="btn btn-sm" style="color:var(--red)" @click="deleteGrade(g.id)">Remove</button>
                </td>
              </tr>
              <tr v-else>
                <td><input v-model="editGrade.name" type="text" class="input" /></td>
                <td><input v-model.number="editGrade.level" class="input" type="number" min="1" style="width:70px" /></td>
                <td><input v-model="editGrade.min_salary" class="input" type="number" /></td>
                <td><input v-model="editGrade.max_salary" class="input" type="number" /></td>
                <td style="text-align:right">
                  <button class="btn btn-primary btn-sm" style="margin-right:4px" :disabled="saving" @click="saveEditGrade">Save</button>
                  <button class="btn btn-ghost btn-sm" @click="editGradeId=null">Cancel</button>
                </td>
              </tr>
            </template>
            <tr v-if="!jobGrades.length"><td colspan="5" class="status">No job grades yet.</td></tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- ══════════  REGULATORY COMPLIANCE (sub-tab within Profile)  ══════════ -->
    <template v-if="tab === 'profile' && profileTab === 'compliance'">
      <input ref="uploadFileInput" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" style="display:none" @change="onFileSelected" />

      <div v-if="complianceLoading" class="org-loading">
        <span class="org-loading-spinner"></span>
        <span>Loading compliance documents…</span>
      </div>
      <p v-if="complianceError" class="error" style="margin-bottom:14px">{{ complianceError }}</p>

      <div v-if="!complianceLoading && complianceDocs.length > 0" class="comp-summary">
        <div class="comp-stat-card"><span class="comp-stat-num">{{ complianceSummary.total }}</span><span class="comp-stat-label">Total</span></div>
        <div class="comp-stat-card comp-stat-ok"><span class="comp-stat-num">{{ complianceSummary.valid }}</span><span class="comp-stat-label">Valid</span></div>
        <div class="comp-stat-card comp-stat-warn"><span class="comp-stat-num">{{ complianceSummary.expiring }}</span><span class="comp-stat-label">Expiring</span></div>
        <div class="comp-stat-card comp-stat-danger"><span class="comp-stat-num">{{ complianceSummary.expired }}</span><span class="comp-stat-label">Expired</span></div>
        <div class="comp-stat-card"><span class="comp-stat-num">{{ complianceSummary.not_uploaded }}</span><span class="comp-stat-label">Not Uploaded</span></div>
      </div>

      <div v-for="seg in complianceSegments" :key="seg.segment" class="comp-segment">
        <div class="comp-segment-header">
          <span class="comp-segment-title">{{ seg.segment }}</span>
          <span class="comp-segment-count">{{ seg.docs.length }} document{{ seg.docs.length !== 1 ? 's' : '' }}</span>
        </div>
        <div class="comp-doc-grid">
          <div
            v-for="doc in seg.docs"
            :key="doc.document_key"
            class="comp-doc-card"
            :class="{
              'comp-doc-card--expired':      doc.status === 'Expired',
              'comp-doc-card--expiring':     doc.status.startsWith('Expiring'),
              'comp-doc-card--valid':        doc.status === 'Valid',
              'comp-doc-card--not-uploaded': doc.status === 'Not Uploaded',
              'comp-doc-card--review':       doc.status === 'Under Review' || doc.status === 'Pending Renewal',
            }"
          >
            <div class="comp-doc-card-header">
              <div class="comp-doc-title-row">
                <span class="comp-doc-label">{{ doc.document_label }}</span>
                <button class="comp-info-btn" title="View renewal guide" @click="openInfo(doc)">ⓘ</button>
              </div>
              <span class="comp-status-badge"
                :class="{
                  'comp-status--expired':      doc.status === 'Expired',
                  'comp-status--expiring':     doc.status.startsWith('Expiring'),
                  'comp-status--valid':        doc.status === 'Valid',
                  'comp-status--not-uploaded': doc.status === 'Not Uploaded',
                  'comp-status--review':       doc.status === 'Under Review' || doc.status === 'Pending Renewal',
                }"
              >{{ doc.status }}</span>
            </div>
            <div class="comp-doc-dates">
              <div class="comp-date-row" v-if="doc.issue_date"><span class="comp-date-label">Issued</span><span class="comp-date-val">{{ doc.issue_date }}</span></div>
              <div class="comp-date-row" v-if="!doc.is_perpetual && doc.expiry_date">
                <span class="comp-date-label">Expires</span>
                <span class="comp-date-val" :class="{ 'comp-date-urgent': doc.status === 'Expired' || doc.status.startsWith('Expiring') }">
                  {{ doc.expiry_date }}<small v-if="doc.days_until_expiry !== null && doc.days_until_expiry >= 0"> ({{ doc.days_until_expiry }}d)</small>
                </span>
              </div>
              <div class="comp-date-row" v-if="doc.is_perpetual"><span class="comp-date-label">Validity</span><span class="comp-date-val">Perpetual</span></div>
              <div class="comp-date-row" v-if="doc.anniversary_date"><span class="comp-date-label">Review</span><span class="comp-date-val">{{ doc.anniversary_date }}</span></div>
            </div>
            <div v-if="doc.file_url" class="comp-file-row">
              <a :href="doc.file_url" target="_blank" class="comp-file-link" rel="noopener noreferrer">📄 {{ doc.file_name || 'View Document' }}</a>
              <button class="comp-remove-btn" title="Remove file" @click="removeFile(doc)">✕</button>
            </div>
            <div class="comp-doc-actions">
              <button class="comp-action-btn comp-action-upload" :disabled="uploadingKey === doc.document_key" @click="triggerUpload(doc)">
                {{ uploadingKey === doc.document_key ? 'Uploading…' : doc.file_url ? '↑ Replace' : '↑ Upload' }}
              </button>
              <button class="comp-action-btn comp-action-dates" @click="openDateDrawer(doc)">📅 Dates</button>
              <button class="comp-action-btn comp-action-reminder" title="Reminder settings" @click="openReminderDrawer(doc)">🔔</button>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- INFO POPUP -->
    <Teleport to="body">
      <div v-if="showInfoPopup" class="comp-overlay" @click.self="closeInfo">
        <div class="comp-popup" role="dialog" aria-modal="true">
          <div class="comp-popup-header">
            <div>
              <h3 class="comp-popup-title">{{ infoDoc?.document_label }}</h3>
              <span class="comp-popup-segment">{{ infoDoc?.segment }}</span>
            </div>
            <button class="comp-popup-close" @click="closeInfo">✕</button>
          </div>
          <div class="comp-popup-body">
            <template v-if="!editingGuide">
              <p v-if="infoDoc?.renewal_guide" class="comp-guide-text" style="white-space:pre-wrap">{{ infoDoc.renewal_guide }}</p>
              <p v-else class="comp-guide-empty">No renewal guide added yet.</p>
              <button class="comp-guide-edit-btn" @click="startEditGuide">✏️ Edit Guide</button>
            </template>
            <template v-else>
              <label class="comp-guide-label">Renewal Procedure Guide</label>
              <textarea v-model="guideEditText" class="comp-guide-textarea" rows="12" placeholder="Enter step-by-step renewal procedure…"></textarea>
              <p v-if="guideError" class="error">{{ guideError }}</p>
              <div class="comp-guide-actions">
                <button class="btn btn-primary btn-sm" :disabled="savingGuide" @click="saveGuide">{{ savingGuide ? 'Saving…' : 'Save Guide' }}</button>
                <button class="btn btn-ghost btn-sm" @click="editingGuide = false">Cancel</button>
              </div>
            </template>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- DATE DRAWER -->
    <Teleport to="body">
      <div v-if="showDateDrawer" class="comp-overlay" @click.self="closeDateDrawer">
        <div class="comp-drawer" role="dialog" aria-modal="true">
          <div class="comp-drawer-header"><h3>Document Dates</h3><button class="comp-popup-close" @click="closeDateDrawer">✕</button></div>
          <div class="comp-drawer-body">
            <p class="comp-drawer-doc-name">{{ editingDoc?.document_label }}</p>
            <label class="comp-field-label">Issue Date</label>
            <input type="date" v-model="dateForm.issue_date" class="comp-field-input" />
            <label class="comp-field-label">Expiry Date <small v-if="editingDoc?.is_perpetual">(not applicable — perpetual)</small></label>
            <input type="date" v-model="dateForm.expiry_date" class="comp-field-input" :disabled="editingDoc?.is_perpetual" />
            <label class="comp-field-label">Anniversary / Review Date</label>
            <input type="date" v-model="dateForm.anniversary_date" class="comp-field-input" />
            <label class="comp-field-label">Notes</label>
            <textarea v-model="dateForm.notes" class="comp-field-input" rows="3" placeholder="Additional notes…"></textarea>
            <p v-if="dateError" class="error">{{ dateError }}</p>
            <div class="comp-drawer-actions">
              <button class="btn btn-primary btn-sm" :disabled="savingDate" @click="saveDates">{{ savingDate ? 'Saving…' : 'Save' }}</button>
              <button class="btn btn-ghost btn-sm" @click="closeDateDrawer">Cancel</button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- REMINDER DRAWER -->
    <Teleport to="body">
      <div v-if="showReminderDrawer" class="comp-overlay" @click.self="closeReminderDrawer">
        <div class="comp-drawer" role="dialog" aria-modal="true">
          <div class="comp-drawer-header"><h3>🔔 Expiry Reminders</h3><button class="comp-popup-close" @click="closeReminderDrawer">✕</button></div>
          <div class="comp-drawer-body">
            <p class="comp-drawer-doc-name">{{ editingReminderDoc?.document_label }}</p>
            <label class="comp-field-label"><input type="checkbox" v-model="reminderForm.reminder_active" /> Enable email reminders</label>
            <label class="comp-field-label">Remind (days before expiry)</label>
            <input type="number" v-model.number="reminderForm.reminder_days_before" class="comp-field-input" min="1" max="365" />
            <label class="comp-field-label">Recipient Emails</label>
            <textarea v-model="reminderForm.reminder_recipients" class="comp-field-input" rows="3" placeholder="hr@company.com, ceo@company.com"></textarea>
            <small class="comp-field-hint">Comma-separated. Reminder emails are sent automatically each day.</small>
            <p v-if="reminderError" class="error">{{ reminderError }}</p>
            <div class="comp-drawer-actions">
              <button class="btn btn-primary btn-sm" :disabled="savingReminder" @click="saveReminder">{{ savingReminder ? 'Saving…' : 'Save' }}</button>
              <button class="btn btn-ghost btn-sm" @click="closeReminderDrawer">Cancel</button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ══════════  REGISTER NEW ORGANISATION MODAL  ══════════ -->
    <Teleport to="body">
      <div v-if="showNewOrg" class="ov-modal-overlay" @click.self="closeNewOrg">
        <div class="ov-modal">
          <div class="ov-modal-header">
            <div>
              <h3>Register New Organisation</h3>
              <p class="ov-modal-sub">The new organisation will be added to your account. You will be assigned as HR Admin.</p>
            </div>
            <button class="ov-modal-close" type="button" @click="closeNewOrg">✕</button>
          </div>
          <div class="ov-modal-body">
            <p v-if="newOrgError" class="error" style="margin-bottom:14px">{{ newOrgError }}</p>

            <div class="ov-form-grid">
              <div class="form-field">
                <label class="ov-label">Organisation Name <span class="ov-req">*</span></label>
                <input v-model="newOrgForm.name" type="text" class="input" placeholder="e.g. Acme Kenya Ltd" />
              </div>
              <div class="form-field">
                <label class="ov-label">Legal / Registered Name</label>
                <input v-model="newOrgForm.legal_name" type="text" class="input" placeholder="Full legal name (if different)" />
              </div>
              <div class="form-field">
                <label class="ov-label">Industry</label>
                <select v-model="newOrgForm.industry" class="input">
                  <option value="">— Select —</option>
                  <option>Technology</option><option>Financial Services</option><option>Healthcare</option>
                  <option>Education</option><option>Manufacturing</option><option>Retail</option>
                  <option>Logistics & Transport</option><option>Consulting</option><option>Media & Communications</option>
                  <option>Government & Public Sector</option><option>NGO / Non-Profit</option><option>Other</option>
                </select>
              </div>
              <div class="form-field">
                <label class="ov-label">Country (ISO 2-letter)</label>
                <input v-model="newOrgForm.country" type="text" class="input" maxlength="2" placeholder="KE" />
              </div>
              <div class="form-field">
                <label class="ov-label">Currency (ISO 3-letter)</label>
                <input v-model="newOrgForm.currency" type="text" class="input" maxlength="3" placeholder="KES" />
              </div>
              <div class="form-field">
                <label class="ov-label">Primary Email</label>
                <input v-model="newOrgForm.primary_email" type="email" class="input" placeholder="hr@company.co.ke" />
              </div>
              <div class="form-field">
                <label class="ov-label">Primary Phone</label>
                <input v-model="newOrgForm.primary_phone" type="tel" class="input" placeholder="+254 700 000 000" />
              </div>
              <div class="form-field ov-edit-field--full">
                <label class="ov-label">Physical / Postal Address</label>
                <textarea v-model="newOrgForm.address" class="input ov-textarea" rows="2" placeholder="Building, street, city, postal code" />
              </div>
            </div>
          </div>
          <div class="ov-modal-footer">
            <button class="btn btn-ghost" type="button" @click="closeNewOrg">Cancel</button>
            <button class="btn btn-primary" type="button" :disabled="newOrgSaving" @click="registerOrg">
              {{ newOrgSaving ? "Registering…" : "Register Organisation" }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.ov-subtitle { font-size: 13px; color: var(--color-text-muted); margin-top: 2px; }
.ov-success  { color: #0d9488; font-size: 13px; font-weight: 500; padding: 8px 12px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: var(--radius-lg); margin-bottom: 10px; }
.ov-meta     { font-size: 12px; color: var(--color-text-muted); margin-top: 2px; }
.ov-label    { font-size: var(--font-size-sm); font-weight: var(--font-weight-semibold); color: var(--color-text); display: block; margin-bottom: 5px; }
.ov-req      { color: var(--color-error); margin-left: 2px; }
.ov-textarea { resize: vertical; font-family: var(--font-family); }

/* Profile card */
.ov-profile-card   { }
.ov-profile-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; }
.ov-profile-grid   { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
.ov-edit-grid      { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
.ov-profile-field  { display: flex; flex-direction: column; gap: 4px; }
.ov-profile-field--full { grid-column: 1 / -1; }
.ov-edit-field--full    { grid-column: 1 / -1; }
.ov-field-label    { font-size: 11px; font-weight: var(--font-weight-semibold); color: var(--color-text-muted); text-transform: uppercase; letter-spacing: .4px; }

/* Orgs table */
.ov-row-active td { background: var(--color-brand-light) !important; }

/* Modal */
.ov-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.ov-modal         { background: #fff; border-radius: var(--radius-lg); width: min(640px, 95vw); max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,.25); }
.ov-modal-header  { display: flex; justify-content: space-between; align-items: flex-start; padding: 20px 24px 16px; border-bottom: 1px solid var(--color-border); }
.ov-modal-header h3 { margin: 0; font-size: 16px; }
.ov-modal-sub     { font-size: 13px; color: var(--color-text-muted); margin: 4px 0 0; }
.ov-modal-close   { background: none; border: none; font-size: 18px; cursor: pointer; color: var(--color-text-muted); padding: 4px; line-height: 1; }
.ov-modal-body    { padding: 20px 24px; }
.ov-modal-footer  { display: flex; justify-content: flex-end; gap: 10px; padding: 16px 24px; border-top: 1px solid var(--color-border); }
.ov-form-grid     { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }

@media (max-width: 640px) {
  .ov-profile-grid, .ov-edit-grid, .ov-form-grid { grid-template-columns: 1fr; }
}

/* Branding section */
.ov-branding-row   { display: flex; gap: 24px; margin-bottom: 24px; padding-bottom: 20px; border-bottom: 1px solid var(--color-border); flex-wrap: wrap; }
.ov-branding-block { display: flex; flex-direction: column; gap: 10px; }

.ov-logo-area     { display: flex; align-items: center; gap: 16px; }
.ov-logo-preview  { width: 100px; height: 56px; border: 1px solid var(--color-border); border-radius: 8px; display: flex; align-items: center; justify-content: center; background: #fff; overflow: hidden; flex-shrink: 0; }
.ov-logo-img      { max-width: 96px; max-height: 52px; object-fit: contain; }
.ov-logo-placeholder { font-size: 11px; color: var(--color-text-light); }
.ov-logo-actions  { display: flex; flex-direction: column; gap: 4px; }
.ov-upload-btn    { cursor: pointer; display: inline-flex; align-items: center; }
.ov-uploading     { opacity: .6; pointer-events: none; }
.ov-logo-error    { color: var(--color-error); font-size: 12px; margin: 0; }
.ov-logo-hint     { font-size: 11px; color: var(--color-text-light); margin: 0; }

.ov-color-display       { display: flex; align-items: center; gap: 10px; }
.ov-color-swatch        { width: 36px; height: 36px; border-radius: 8px; border: 1px solid var(--color-border); flex-shrink: 0; }
.ov-color-value         { font-size: 14px; font-weight: 500; color: var(--color-text); }
.ov-color-hint          { font-size: 12px; color: var(--color-text-light); }

.ov-color-picker-row    { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.ov-color-input         { width: 40px; height: 40px; padding: 2px; border: 1px solid var(--color-border); border-radius: 8px; cursor: pointer; background: none; }
.ov-color-text          { width: 110px !important; font-family: monospace; }
.ov-color-hint-sm       { font-size: 11px; color: var(--color-text-light); }

/* Job Categories tab */
.ov-cat-card  { position: relative; }
.ov-cat-table { width: 100%; border-collapse: collapse; }
.ov-cat-table th, .ov-cat-table td { padding: 10px 12px; border-bottom: 1px solid var(--color-border); font-size: var(--font-size-sm); }
.ov-cat-table th { font-weight: var(--font-weight-semibold); color: var(--color-text-muted); text-transform: uppercase; font-size: 11px; letter-spacing: .4px; background: var(--color-neutral-50); }
.ov-cat-check  { width: 36px; text-align: center; }
.ov-cat-desc   { color: var(--color-text-muted); }
.ov-cat-actions { text-align: right; white-space: nowrap; width: 1%; }
.ov-cat-selected td { background: var(--color-brand-subtle); }
.ov-cat-editing td { background: var(--color-neutral-50); }
.ov-cat-add-row td { background: #f0fdf4; }
.ov-checkbox { width: 15px; height: 15px; cursor: pointer; accent-color: var(--color-brand-base); }
.ov-icon-btn  { background: none; border: none; cursor: pointer; padding: 4px 6px; border-radius: 4px; font-size: 15px; opacity: .7; transition: opacity .15s, background .15s; }
.ov-icon-btn:hover { opacity: 1; background: var(--color-neutral-100); }
.ov-icon-btn--danger:hover { background: #fee2e2; }

/* Org list link */
.ov-org-link { color: var(--color-brand-base); text-decoration: none; }
.ov-org-link:hover { text-decoration: underline; }

/* ── Profile sub-tab bar ─────────────────────────────────────────────────────── */
.ov-subtabs { border-bottom: 1px solid var(--color-border); padding-bottom: 0; margin-bottom: 20px; }

/* ── Compliance section divider ─────────────────────────────────────────────── */
.comp-section-divider { display: flex; align-items: baseline; gap: 12px; margin: 28px 0 20px; padding-bottom: 10px; border-bottom: 2px solid var(--color-brand-light); }
.comp-section-divider-title { font-size: 1.05rem; font-weight: 700; color: var(--color-brand-base); letter-spacing: 0.01em; }
.comp-section-divider-sub { font-size: 0.8rem; color: var(--color-text-muted); }

/* ── Compliance ─────────────────────────────────────────────────────────────── */
.comp-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 12px; margin-bottom: 24px; }
.comp-stat-card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 10px; padding: 14px 16px; display: flex; flex-direction: column; align-items: center; gap: 4px; }
.comp-stat-num { font-size: 2rem; font-weight: 700; line-height: 1; color: var(--color-text); }
.comp-stat-label { font-size: 0.75rem; color: var(--color-text-muted); text-align: center; }
.comp-stat-ok .comp-stat-num { color: #16a34a; }
.comp-stat-warn .comp-stat-num { color: #d97706; }
.comp-stat-danger .comp-stat-num { color: #dc2626; }

.comp-segment { margin-bottom: 28px; }
.comp-segment-header { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 2px solid var(--color-brand-light); }
.comp-segment-title { font-size: 0.95rem; font-weight: 700; color: var(--color-brand-base); text-transform: uppercase; letter-spacing: 0.04em; }
.comp-segment-count { font-size: 0.78rem; color: var(--color-text-muted); background: var(--color-neutral-100); padding: 2px 8px; border-radius: 20px; }

.comp-doc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }
.comp-doc-card { background: var(--color-surface); border: 1px solid var(--color-border); border-left: 3px solid #94a3b8; border-radius: 10px; padding: 14px; display: flex; flex-direction: column; gap: 10px; transition: box-shadow 0.15s; }
.comp-doc-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.07); }
.comp-doc-card--expiring   { border-left-color: #f59e0b; }
.comp-doc-card--expired    { border-left-color: #ef4444; }
.comp-doc-card--valid      { border-left-color: #22c55e; }
.comp-doc-card--not-uploaded { border-left-color: #94a3b8; }
.comp-doc-card--review     { border-left-color: #8b5cf6; }

.comp-doc-card-header { display: flex; flex-direction: column; gap: 6px; }
.comp-doc-title-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
.comp-doc-label { font-size: 0.875rem; font-weight: 600; color: var(--color-text); line-height: 1.3; flex: 1; }
.comp-info-btn { background: none; border: none; font-size: 1rem; cursor: pointer; color: var(--color-text-muted); padding: 0 2px; flex-shrink: 0; line-height: 1; }
.comp-info-btn:hover { color: var(--color-brand-base); }

.comp-status-badge { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 0.72rem; font-weight: 600; letter-spacing: 0.03em; width: fit-content; }
.comp-status--not-uploaded { background: #f1f5f9; color: #64748b; }
.comp-status--valid        { background: #dcfce7; color: #15803d; }
.comp-status--expiring     { background: #fef3c7; color: #b45309; }
.comp-status--expired      { background: #fee2e2; color: #b91c1c; }
.comp-status--review       { background: #ede9fe; color: #6d28d9; }

.comp-doc-dates { display: flex; flex-direction: column; gap: 3px; }
.comp-date-row { display: flex; gap: 8px; font-size: 0.78rem; }
.comp-date-label { color: var(--color-text-muted); width: 52px; flex-shrink: 0; }
.comp-date-val { color: var(--color-text); }
.comp-date-urgent { color: #ef4444; font-weight: 600; }

.comp-file-row { display: flex; align-items: center; gap: 8px; }
.comp-file-link { font-size: 0.78rem; color: var(--color-brand-base); text-decoration: none; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; }
.comp-file-link:hover { text-decoration: underline; }
.comp-remove-btn { background: none; border: none; color: #94a3b8; cursor: pointer; font-size: 0.75rem; padding: 2px 4px; border-radius: 4px; }
.comp-remove-btn:hover { background: #fee2e2; color: #ef4444; }

.comp-doc-actions { display: flex; gap: 6px; flex-wrap: wrap; margin-top: auto; }
.comp-action-btn { flex: 1; padding: 5px 8px; border: 1px solid var(--color-border); border-radius: 6px; font-size: 0.78rem; font-weight: 500; cursor: pointer; background: var(--color-neutral-50); color: var(--color-text); transition: background 0.12s; white-space: nowrap; }
.comp-action-btn:hover:not(:disabled) { background: var(--color-neutral-100); }
.comp-action-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.comp-action-upload { border-color: var(--color-brand-base); color: var(--color-brand-base); }
.comp-action-upload:hover:not(:disabled) { background: var(--color-brand-light); }
.comp-action-reminder { flex: 0 0 auto; }

.comp-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 200; display: flex; align-items: center; justify-content: center; padding: 16px; }

/* ── Org Hierarchy Tree ─────────────────────────────────── */
.hs-toolbar { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 20px; gap: 12px; flex-wrap: wrap; }
.hs-toolbar-title { font-size: 1.05rem; font-weight: 700; color: var(--color-text); margin: 0 0 2px; }
.hs-toolbar-sub { font-size: 0.78rem; color: var(--color-text-muted); margin: 0; }

.org-loading { display: flex; align-items: center; gap: 10px; padding: 24px; color: var(--color-text-muted); }
.org-loading-spinner { width: 18px; height: 18px; border: 2px solid var(--color-brand-light); border-top-color: var(--color-brand-base); border-radius: 50%; animation: spin 0.7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.hs-tree { display: flex; flex-direction: column; gap: 10px; }

/* Level 1 – Functional Unit */
.hs-unit { border-radius: 10px; background: #fff; border: 1.5px solid var(--color-brand-base); overflow: hidden; }
.hs-unit-unassigned { border-color: #94a3b8; }
.hs-unit-header { display: flex; align-items: center; gap: 10px; padding: 12px 14px; cursor: pointer; user-select: none; background: var(--color-brand-light); }
.hs-unit-unassigned .hs-unit-header { background: #f8fafc; }
.hs-unit-header:hover { background: #dbeafe; }
.hs-unit-unassigned .hs-unit-header:hover { background: #f1f5f9; }

/* Level 2 – Department */
.hs-children { padding: 6px 10px 10px 24px; display: flex; flex-direction: column; gap: 6px; }
.hs-dept { border-radius: 8px; background: #f8fafc; border: 1px solid #e2e8f0; overflow: hidden; }
.hs-dept-header { display: flex; align-items: center; gap: 8px; padding: 9px 12px; cursor: pointer; user-select: none; }
.hs-dept-header:hover { background: #f1f5f9; }

/* Level 3 – Business Unit */
.hs-bu { border-radius: 7px; background: #fff; border: 1px solid #e2e8f0; overflow: hidden; }
.hs-bu-header { display: flex; align-items: center; gap: 8px; padding: 8px 12px; cursor: pointer; user-select: none; }
.hs-bu-header:hover { background: #f8fafc; }

/* Level 4 – Practice */
.hs-practice { display: flex; align-items: center; gap: 8px; padding: 7px 10px; border-radius: 6px; background: #f8fafc; border: 1px solid #e2e8f0; }

/* Shared node layout */
.hs-node-info { flex: 1; display: flex; flex-wrap: wrap; align-items: center; gap: 6px; min-width: 0; }
.hs-node-name { font-weight: 600; font-size: 0.9rem; color: var(--color-text); }
.hs-node-code { font-size: 0.75rem; color: var(--color-brand-base); background: var(--color-brand-light); border-radius: 4px; padding: 1px 6px; font-weight: 600; }
.hs-node-lead { font-size: 0.75rem; color: #64748b; }
.hs-node-count { font-size: 0.73rem; color: #94a3b8; }
.hs-unit-icon, .hs-dept-icon, .hs-bu-icon, .hs-practice-icon { font-size: 1rem; flex-shrink: 0; }

/* Chevron toggle */
.hs-chevron { font-size: 0.65rem; color: #94a3b8; transition: transform 0.2s; flex-shrink: 0; }
.hs-chevron.open { transform: rotate(90deg); color: var(--color-brand-base); }

/* Action buttons */
.hs-node-actions { display: flex; gap: 4px; flex-shrink: 0; }
.hs-act-btn { font-size: 0.72rem; padding: 3px 8px; border: 1px solid #e2e8f0; border-radius: 5px; background: #fff; cursor: pointer; color: #374151; white-space: nowrap; }
.hs-act-btn:hover { background: #f1f5f9; border-color: #94a3b8; }
.hs-act-danger { color: #ef4444; }
.hs-act-danger:hover { background: #fef2f2; border-color: #fca5a5; }

/* Empty states */
.hs-empty { font-size: 0.82rem; color: #94a3b8; padding: 8px 4px; display: flex; align-items: center; gap: 8px; }
.hs-inline-add { background: none; border: none; color: var(--color-brand-base); cursor: pointer; font-size: 0.82rem; padding: 0; text-decoration: underline; }
.hs-empty-state { text-align: center; padding: 48px 24px; color: #94a3b8; display: flex; flex-direction: column; gap: 12px; align-items: center; }

/* form hint */
.comp-field-hint { font-size: 0.75rem; color: #94a3b8; margin-top: 2px; display: block; }
.comp-popup { background: #fff; border-radius: 12px; width: 100%; max-width: 560px; max-height: 80vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.2); display: flex; flex-direction: column; }
.comp-popup-header { display: flex; align-items: flex-start; justify-content: space-between; padding: 18px 20px 12px; border-bottom: 1px solid var(--color-border); gap: 12px; }
.comp-popup-title { font-size: 1rem; font-weight: 700; color: var(--color-text); margin: 0 0 4px; }
.comp-popup-segment { font-size: 0.75rem; color: var(--color-brand-base); background: var(--color-brand-light); padding: 2px 8px; border-radius: 20px; }
.comp-popup-close { background: none; border: none; font-size: 1.1rem; cursor: pointer; color: var(--color-text-muted); padding: 0; flex-shrink: 0; }
.comp-popup-close:hover { color: #ef4444; }
.comp-popup-body { padding: 18px 20px; flex: 1; overflow-y: auto; }
.comp-guide-text { font-size: 0.85rem; line-height: 1.6; color: var(--color-text); }
.comp-guide-empty { color: var(--color-text-muted); font-style: italic; font-size: 0.85rem; }
.comp-guide-edit-btn { background: none; border: none; color: var(--color-brand-base); font-size: 0.82rem; cursor: pointer; padding: 0; margin-top: 12px; display: block; }
.comp-guide-label { display: block; font-size: 0.82rem; font-weight: 600; color: var(--color-text-muted); margin-bottom: 6px; }
.comp-guide-textarea { width: 100%; border: 1px solid var(--color-border); border-radius: 6px; padding: 10px; font-size: 0.83rem; line-height: 1.5; resize: vertical; font-family: inherit; box-sizing: border-box; }
.comp-guide-actions { display: flex; gap: 8px; margin-top: 12px; }

.comp-drawer { background: #fff; border-radius: 12px; width: 100%; max-width: 420px; max-height: 85vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.2); display: flex; flex-direction: column; }
.comp-drawer-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px 12px; border-bottom: 1px solid var(--color-border); }
.comp-drawer-header h3 { margin: 0; font-size: 0.95rem; }
.comp-drawer-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 10px; }
.comp-drawer-doc-name { font-weight: 600; font-size: 0.875rem; color: var(--color-text); margin: 0 0 4px; padding: 8px 12px; background: var(--color-neutral-50); border-radius: 6px; }
.comp-field-label { font-size: 0.8rem; font-weight: 600; color: var(--color-text-muted); display: flex; align-items: center; gap: 6px; }
.comp-field-input { width: 100%; border: 1px solid var(--color-border); border-radius: 6px; padding: 8px 10px; font-size: 0.85rem; font-family: inherit; box-sizing: border-box; }
.comp-field-input:disabled { background: var(--color-neutral-50); color: var(--color-text-muted); }
.comp-field-hint { color: var(--color-text-muted); font-size: 0.75rem; line-height: 1.4; margin-top: -6px; }
.comp-drawer-actions { display: flex; gap: 8px; margin-top: 6px; }



/* Explanatory line under a configuration heading. */
.ov-hint { font-size: .78rem; color: var(--color-text-muted); margin: 0 0 10px; }
</style>