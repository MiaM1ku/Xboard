import { useState } from 'react'
import useSWR from 'swr'
import { Activity, Network, Wifi } from 'lucide-react'
import { api } from '../lib/api'
import { formatBytes, formatDate } from '../lib/format'
import type { Group } from '../types'
import { Badge, Card, ErrorState, LoadingTable, Modal, Pagination } from './ui'

interface ActivityNode {
  id: number
  name: string
  type: string
  host: string
  port: number | string
  device_count?: number
  ips?: string[]
}

interface TrafficRecord {
  id: number
  u: number
  d: number
  server_rate: number | string
  record_at: number
}

interface UserActivityData {
  user: {
    id: number
    email: string
    u: number
    d: number
    transfer_enable: number
    online_count: number
    group?: Group | null
  }
  traffic: {
    data: TrafficRecord[]
    total: number
    current_page: number
    last_page: number
  }
  authorized_nodes: ActivityNode[]
  online_nodes: ActivityNode[]
}

export function UserActivityModal({ userId, email, onClose }: {
  userId: number
  email: string
  onClose: () => void
}) {
  const [page, setPage] = useState(1)
  const request = useSWR(['user-activity', userId, page], () => api.adminPost<UserActivityData>('user/activity', {
    id: userId,
    page,
    page_size: 10,
  }))
  const data = request.data
  const used = Number(data?.user.u || 0) + Number(data?.user.d || 0)
  const remaining = Math.max(0, Number(data?.user.transfer_enable || 0) - used)

  return (
    <Modal open onClose={onClose} width="large" title={`使用记录 · ${email}`} description="流量历史来自每日统计表；当前在线节点来自 xbnode 最近 5 分钟的设备快照，授权节点仅表示该用户可以连接。">
      {request.error ? <ErrorState error={request.error} retry={() => void request.mutate()} /> : !data ? <LoadingTable rows={8} columns={4} /> : <div className="form-section">
        <div className="kpi-grid user-activity-kpis">
          <Card className="kpi-card"><div className="kpi-top"><span>累计已用</span><Activity size={15} /></div><div className="kpi-value">{formatBytes(used)}</div><div className="kpi-hint">上传 {formatBytes(data.user.u)} · 下载 {formatBytes(data.user.d)}</div></Card>
          <Card className="kpi-card"><div className="kpi-top"><span>剩余流量</span><Network size={15} /></div><div className="kpi-value">{data.user.transfer_enable ? formatBytes(remaining) : '不限'}</div><div className="kpi-hint">总额度 {data.user.transfer_enable ? formatBytes(data.user.transfer_enable) : '未限制'}</div></Card>
          <Card className="kpi-card"><div className="kpi-top"><span>当前设备</span><Wifi size={15} /></div><div className="kpi-value">{data.user.online_count}</div><div className="kpi-hint">{data.online_nodes.length} 个节点有在线快照</div></Card>
        </div>

        <section className="activity-section">
          <div className="card-header"><div><h2>当前在线节点</h2><p>这是实际在线快照，不会把多机器上报互相覆盖。</p></div></div>
          {data.online_nodes.length ? <div className="activity-node-grid">{data.online_nodes.map((node) => <div className="activity-node" key={node.id}><div><strong>{node.name}</strong><span>{node.type.toUpperCase()} · {node.host}:{node.port}</span></div><Badge tone="success">{node.device_count || 0} 设备</Badge>{node.ips?.length ? <code>{node.ips.join(' · ')}</code> : null}</div>)}</div> : <p className="muted">当前没有节点报告该用户在线。</p>}
        </section>

        <section className="activity-section">
          <div className="card-header"><div><h2>授权可用节点</h2><p>由权限组“{data.user.group?.name || '未分组'}”决定，不代表用户已经连接。</p></div><Badge tone="info">{data.authorized_nodes.length} 个</Badge></div>
          <div className="connection-methods">{data.authorized_nodes.map((node) => <Badge key={node.id}>#{node.id} {node.name}</Badge>)}{!data.authorized_nodes.length ? <span className="muted">没有可用节点</span> : null}</div>
        </section>

        <section className="activity-section">
          <div className="card-header"><div><h2>每日流量使用记录</h2><p>倍率后的计费流量已经计入用户累计用量；这里保留上行、下行和节点倍率。</p></div><Badge>{data.traffic.total} 条</Badge></div>
          <div className="table-wrap">
            <table className="data-table">
              <thead><tr><th>日期</th><th>上传</th><th>下载</th><th>合计</th><th>倍率</th></tr></thead>
              <tbody>{data.traffic.data.map((record) => <tr key={record.id}><td>{formatDate(record.record_at)}</td><td>{formatBytes(record.u)}</td><td>{formatBytes(record.d)}</td><td><strong>{formatBytes(Number(record.u) + Number(record.d))}</strong></td><td>{Number(record.server_rate).toFixed(2)}x</td></tr>)}</tbody>
            </table>
            {!data.traffic.data.length ? <p className="muted" style={{ padding: 16 }}>暂无流量日记录。</p> : null}
          </div>
          {data.traffic.last_page > 1 ? <Pagination page={data.traffic.current_page} lastPage={data.traffic.last_page} total={data.traffic.total} onChange={setPage} /> : null}
        </section>
      </div>}
    </Modal>
  )
}
