const BASE = '/api'

async function req(method, url, body) {
  const res = await fetch(BASE + url, {
    method,
    headers: body ? { 'Content-Type': 'application/json' } : undefined,
    body: body ? JSON.stringify(body) : undefined,
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const err = new Error(data.message || `请求失败 (${res.status})`)
    err.status = res.status
    err.data = data
    throw err
  }
  return data
}

export const api = {
  get: (url) => req('GET', url),
  post: (url, body) => req('POST', url, body),
  patch: (url, body) => req('PATCH', url, body),
}

export const fmtFee = (cents) => `¥${(cents / 100).toFixed(2)}`

export const ORDER_STATUS = {
  dispensing: '待取伞（柜门已开）',
  active: '借用中',
  returning: '待确认入柜',
  completed: '已完成',
  cancelled: '已取消',
}

export const UMBRELLA_STATUS = {
  in_slot: '在柜可借',
  borrowed: '借出中',
  cleaning: '清洁中',
  repair: '维修中',
  lost: '已丢失',
}
