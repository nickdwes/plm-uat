import { ref, computed } from 'vue';

const organizationId = ref<number | null>(parseInt(localStorage.getItem('organizationId') || '1'));

export function useOrganization() {
  const organization = computed({
    get: () => organizationId.value,
    set: (value) => {
      organizationId.value = value;
      if (value) {
        localStorage.setItem('organizationId', String(value));
      }
    },
  });

  return {
    organization,
    setOrganization: (id: number) => {
      organization.value = id;
    },
  };
}
