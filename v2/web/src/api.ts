export type Meta = { version: string; env: string }

export async function fetchMeta(): Promise<Meta> {
  const res = await fetch('/api/v1/meta', { credentials: 'same-origin' })
  if (!res.ok) throw new Error(`HTTP ${res.status}`)
  return res.json()
}
