import { useEffect, useState } from 'react'
import { api, fmtFee, fmtTime, UMBRELLA_STATUS } from '../api.js'

/** 管理端:伞号定位、清洁/维修/丢失处理、柜机离线切换、事件记录、对账 */
export default function AdminPage() {
  const [code, setCode] = useState('UMB-1001')
  const [umbrella, setUmbrella] = useState(null)
  const [umbrellaEvents, setUmbrellaEvents] = useState([])
  const [cabinets, setCabinets] = useState([])
  const [events, setEvents] = useState([])
  const [report, setReport] = useState(null)
  const [message, setMessage] = useState('')

  const loadCabinets = () => api.get('/admin/cabinets').then((d) => setCabinets(d.cabinets)).catch(() => {})
  const loadEvents = () => api.get('/admin/events').then((d) => setEvents(d.events)).catch(() => {})

  useEffect(() => {
    loadCabinets()
    loadEvents()
    const timer = setInterval(() => { loadCabinets(); loadEvents() }, 3000)
    return () => clearInterval(timer)
  }, [])

  const lookup = async () => {
    setMessage('')
    try {
      const data = await api.get(`/admin/umbrellas/${encodeURIComponent(code.trim())}`)
      setUmbrella(data.umbrella)
      setUmbrellaEvents(data.events)
    } catch (e) {
      setUmbrella(null)
      setMessage(e.message)
    }
  }

  const action = async (act) => {
    setMessage('')
    try {
      await api.post(`/admin/umbrellas/${encodeURIComponent(umbrella.code)}/action`, { action: act })
      setMessage('操作成功')
      lookup()
    } catch (e) { setMessage(e.message) }
  }

  const toggleCabinet = async (cabinet) => {
    await api.post(`/admin/cabinets/${cabinet.code}/connectivity`, { online: !cabinet.online })
    loadCabinets()
  }

  const reconcile = async () => {
    const data = await api.get('/admin/reconcile')
    setReport(data)
  }

  return (
    <>
      <h2>雨伞定位与运维</h2>
      <div className="card">
        <div className="row">
          <input value={code} onChange={(e) => setCode(e.target.value)} placeholder="输入伞号,如 UMB-1001" />
          <button className="shrink" onClick={lookup}>查询位置</button>
        </div>
        {message && <div className="notice info">{message}</div>}

        {umbrella && (
          <div className="mt">
            <div className="row">
              <div>
                <b>{umbrella.code}</b>{' '}
                <span className={`badge ${umbrella.status === 'in_slot' ? 'on' : umbrella.status === 'lost' ? 'off' : 'warn'}`}>
                  {UMBRELLA_STATUS[umbrella.status]}
                </span>
                <div className="muted mt">
                  {umbrella.location?.type === 'in_slot' && <>位置:{umbrella.location.station} · 柜门 {umbrella.location.slot_label}</>}
                  {umbrella.location?.type === 'with_user' && (
                    <>由 {umbrella.location.user_name} 借出(订单 {umbrella.location.order_no},{fmtTime(umbrella.location.borrowed_at)} 起)</>
                  )}
                  {!umbrella.location && '位置未知'}
                </div>
              </div>
              <div className="shrink row">
                <button className="small ghost" onClick={() => action('start_cleaning')}>登记清洁</button>
                <button className="small ghost" onClick={() => action('start_repair')}>登记维修</button>
                <button className="small ghost" onClick={() => action('restore')}>恢复上架</button>
                <button className="small danger" onClick={() => action('mark_lost')}>登记丢失</button>
              </div>
            </div>

            {umbrellaEvents.length > 0 && (
              <table className="mt">
                <thead><tr><th>时间</th><th>事件</th><th>柜机</th><th>结果</th></tr></thead>
                <tbody>
                  {umbrellaEvents.map((e, i) => (
                    <tr key={i}>
                      <td className="mono">{fmtTime(e.occurred_at)}</td>
                      <td>{e.type}</td>
                      <td className="mono">{e.cabinet_code}</td>
                      <td>{e.result}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        )}
      </div>

      <h2>柜机控制(模拟离线 / 补传)</h2>
      <div className="card">
        <table>
          <thead><tr><th>柜机</th><th>站点</th><th>状态</th><th>最近心跳</th><th></th></tr></thead>
          <tbody>
            {cabinets.map((c) => (
              <tr key={c.code}>
                <td className="mono">{c.code}</td>
                <td>{c.station}</td>
                <td><span className={`badge ${c.online ? 'on' : 'off'}`}>{c.online ? '在线' : '离线'}</span></td>
                <td className="mono">{fmtTime(c.last_seen_at)}</td>
                <td>
                  <button className="small ghost" onClick={() => toggleCabinet(c)}>
                    {c.online ? '切换离线' : '恢复在线'}
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        <div className="muted mt">离线期间柜机照常工作,事件缓存在本地;恢复在线后自动批量补传,服务端按序号核对去重。</div>
      </div>

      <h2>对账</h2>
      <div className="card">
        <button className="ghost" onClick={reconcile}>立即核对</button>
        {report && (
          <div className="mt">
            <span className={`badge ${report.consistent ? 'on' : 'off'}`}>
              {report.consistent ? '柜位 / 雨伞 / 订单三方一致' : '发现不一致'}
            </span>
            {report.anomalies.length > 0 && (
              <ul>{report.anomalies.map((a, i) => <li key={i} className="muted">{a}</li>)}</ul>
            )}
            <table className="mt">
              <thead><tr><th>柜机</th><th>总柜位</th><th>占用</th><th>空位</th><th>预留</th><th>在架可借</th><th>维护中</th></tr></thead>
              <tbody>
                {report.cabinets.map((c) => (
                  <tr key={c.cabinet_code}>
                    <td className="mono">{c.cabinet_code}</td>
                    <td>{c.total_slots}</td><td>{c.occupied_slots}</td><td>{c.empty_slots}</td>
                    <td>{c.reserved_slots}</td><td>{c.umbrellas_in_slot}</td><td>{c.maintenance}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <h2>设备事件记录</h2>
      <div className="card">
        <table>
          <thead><tr><th>序号</th><th>柜机</th><th>事件</th><th>柜门</th><th>伞号</th><th>设备时间</th><th>结果</th></tr></thead>
          <tbody>
            {events.slice(0, 30).map((e) => (
              <tr key={e.event_uid}>
                <td className="mono">{e.device_seq}</td>
                <td className="mono">{e.cabinet_code}</td>
                <td>{e.type}</td>
                <td>{e.slot_label ?? '—'}</td>
                <td className="mono">{e.umbrella_code ?? '—'}</td>
                <td className="mono">{fmtTime(e.occurred_at)}</td>
                <td>
                  <span className={`badge ${e.result === 'applied' ? 'on' : e.result === 'duplicate' ? 'neutral' : 'off'}`}>
                    {e.result}
                  </span>
                  {e.note && <span className="muted"> {e.note}</span>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  )
}
