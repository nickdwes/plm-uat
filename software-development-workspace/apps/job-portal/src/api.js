const BASE = import.meta.env.VITE_API_BASE ?? 'http://localhost'

export async function getJobs(params = {}) {
  const qs = new URLSearchParams(Object.fromEntries(Object.entries(params).filter(([, v]) => v !== undefined && v !== ''))).toString()
  const res = await fetch(`${BASE}/api/public/jobs${qs ? '?' + qs : ''}`)
  if (!res.ok) throw new Error('Failed to load jobs')
  return res.json()
}

export async function getJob(uuid) {
  const res = await fetch(`${BASE}/api/public/jobs/${uuid}`)
  if (res.status === 404) throw new Error('This vacancy is no longer available.')
  if (!res.ok) throw new Error('Failed to load job details')
  return res.json()
}

export async function applyToJob(uuid, formData) {
  const res = await fetch(`${BASE}/api/public/jobs/${uuid}/apply`, {
    method: 'POST',
    body: formData,
  })
  const json = await res.json().catch(() => ({}))
  if (!res.ok) {
    // Surface Laravel validation errors
    if (json.errors) {
      const msgs = Object.values(json.errors).flat()
      throw new Error(msgs.join(' '))
    }
    throw new Error(json.message ?? 'Submission failed. Please try again.')
  }
  return json
}
