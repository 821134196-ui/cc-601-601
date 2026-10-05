/** 简单的 API 封装:所有请求走 Vite 代理到 Laravel */
async function request(path, options = {}) {
  const res = await fetch(`/api${path}`, {
    headers: { 'Content-Type': 'application/json' },
    ...options,
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const err = new Error(data.message || `请求失败 (${res.status})`)
    err.status = res.status
    err.payload = data
    throw err
  }
  return data
}

export const api = {
  get: (path) => request(path),
  post: (path, body) => request(path, { method: 'POST', body: JSON.stringify(body ?? {}) }),
}

export const fmtTime = (iso) => (iso ? new Date(iso).toLocaleString('zh-CN', { hour12: false }) : '—')

export const fmtFee = (cents) => (cents == null ? '—' : `¥${(cents / 100).toFixed(2)}`)

export const ORDER_STATUS = {
  dispensing: '等待取伞',
  borrowed: '借出中',
  returning: '还伞中',
  completed: '已完成',
  cancelled: '已取消',
}

export const UMBRELLA_STATUS = {
  in_slot: '在架可借',
  dispatching: '放出中',
  borrowed: '借出中',
  cleaning: '清洁中',
  repair: '维修中',
  lost: '已丢失',
}
