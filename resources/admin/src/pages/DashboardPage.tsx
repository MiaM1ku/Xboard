import { lazy, Suspense } from 'react'
import useSWR from 'swr'
import { Activity, ArrowDownUp, Network, ServerCog, UsersRound, Waypoints } from 'lucide-react'
import { api } from '../lib/api'
import { formatBytes, relativeTime } from '../lib/format'
import type { Machine, NodeRecord } from '../types'
import { Badge, Card, ErrorState, LoadingTable, PageHeader } from '../components/ui'

const TrafficDashboard = lazy(() => import('../components/TrafficDashboard').then((module) => ({ default: module.TrafficDashboard })))

interface Stats {
  online_nodes?: number
  online_devices?: number
  online_users?: number
  today_traffic?: { total?: number }
  month_traffic?: { total?: number }
  total_traffic?: { total?: number }
  month_register_total?: number
}

function isRecentlyOnline(machine: Machine) {
  if (!machine.is_active || !machine.last_seen_at) return false
  const value = typeof machine.last_seen_at === 'number' && machine.last_seen_at < 10_000_000_000
    ? machine.last_seen_at * 1000
    : new Date(machine.last_seen_at).getTime()
  return Date.now() - value < 5 * 60 * 1000
}

function Kpi({ title, value, hint, icon }: { title: string; value: string | number; hint: string; icon: React.ReactNode }) {
  return (
    <Card className="kpi-card">
      <div className="kpi-top"><span>{title}</span><span className="kpi-icon">{icon}</span></div>
      <div className="kpi-value">{value}</div>
      <div className="kpi-hint">{hint}</div>
    </Card>
  )
}

export function DashboardPage({ onNavigate }: { onNavigate: (page: 'nodes' | 'machines' | 'routing') => void }) {
  const stats = useSWR('dashboard-stats', () => api.adminGet<{ data: Stats }>('stat/getOverride'))
  const nodes = useSWR('dashboard-nodes', () => api.adminGet<NodeRecord[]>('server/manage/getNodes'))
  const machines = useSWR('dashboard-machines', () => api.adminGet<Machine[]>('server/machine/fetch'))
  const value = stats.data?.data || {}
  const machineList = machines.data || []
  const nodeList = nodes.data || []
  const onlineMachines = machineList.filter(isRecentlyOnline).length
  const multiBackendNodes = nodeList.filter((node) => node.machine_ids?.length > 1).length
  const hasError = stats.error || nodes.error || machines.error

  return (
    <>
      <PageHeader title="仪表盘" description="集中查看节点、服务器和设备来源状态。商业收入指标已从自用构建中移除。" />
      <div className="kpi-grid">
        <Kpi title="在线节点" value={value.online_nodes ?? nodeList.filter((node) => node.is_online).length} hint={`共 ${nodeList.length} 个节点`} icon={<Network size={16} />} />
        <Kpi title="在线设备" value={value.online_devices ?? 0} hint={`${value.online_users ?? 0} 个活跃用户`} icon={<UsersRound size={16} />} />
        <Kpi title="在线服务器" value={`${onlineMachines} / ${machineList.length}`} hint="最近 5 分钟内有心跳" icon={<ServerCog size={16} />} />
        <Kpi title="本月流量" value={formatBytes(value.month_traffic?.total)} hint={`今日 ${formatBytes(value.today_traffic?.total)}`} icon={<ArrowDownUp size={16} />} />
      </div>

      {hasError ? <ErrorState error={hasError} retry={() => { void stats.mutate(); void nodes.mutate(); void machines.mutate() }} /> : null}

      <Suspense fallback={<LoadingTable rows={8} columns={4} />}><TrafficDashboard /></Suspense>

      <div className="dashboard-grid">
        <Card>
          <div className="card-header">
            <div><h2>节点运行概览</h2><p>按节点聚合全部独立后端与机器来源，不会让后上报的机器覆盖其他来源。</p></div>
            <button className="button button-secondary button-small" type="button" onClick={() => onNavigate('nodes')}>管理节点</button>
          </div>
          {!nodes.data ? <LoadingTable rows={6} columns={5} /> : (
            <div className="table-wrap">
              <table className="data-table">
                <thead><tr><th>节点</th><th>状态</th><th>在线</th><th>后端</th><th>最后状态</th></tr></thead>
                <tbody>
                  {nodeList.slice(0, 8).map((node) => (
                    <tr key={node.id}>
                      <td><div className="node-cell"><span className={`status-dot ${node.is_online ? 'online' : ''}`} /><div className="table-primary"><strong>{node.name}</strong><span>{node.type.toUpperCase()} · {node.host}:{node.port}</span></div></div></td>
                      <td><Badge tone={node.enabled ? 'success' : 'neutral'}>{node.enabled ? '已启用' : '已停用'}</Badge></td>
                      <td>{node.online ?? 0} 用户 / {node.online_conn ?? 0} 连接</td>
                      <td>{node.machine_ids?.length ? `${node.machine_ids.length} 台机器` : '独立 node_id'}</td>
                      <td className="muted">{node.available_status || node.health_status || (node.is_online ? '正常' : '暂无上报')}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {!nodeList.length ? <div className="empty-state"><strong>暂无节点</strong><p>创建节点后会在这里显示聚合状态。</p></div> : null}
            </div>
          )}
        </Card>

        <div style={{ display: 'grid', gap: 16, alignContent: 'start' }}>
          <Card>
            <div className="card-header"><div><h2>分发结构</h2><p>当前多后端与路由覆盖情况</p></div><Waypoints size={18} className="muted" /></div>
            <div className="card-body info-list">
              <div className="info-row"><span>多后端节点</span><span><strong>{multiBackendNodes}</strong> 个</span></div>
              <div className="info-row"><span>独立 node_id</span><span><strong>{nodeList.filter((node) => !node.machine_ids?.length).length}</strong> 个</span></div>
              <div className="info-row"><span>服务器绑定</span><span><strong>{nodeList.reduce((sum, node) => sum + (node.machine_ids?.length || 0), 0)}</strong> 条</span></div>
              <div className="info-row"><span>总计流量</span><span><strong>{formatBytes(value.total_traffic?.total)}</strong></span></div>
            </div>
          </Card>
          <Card>
            <div className="card-header"><div><h2>服务器心跳</h2><p>最后收到 xbnode 上报</p></div><Activity size={18} className="muted" /></div>
            <div className="card-body info-list">
              {machineList.slice(0, 5).map((machine) => (
                <div className="info-row" key={machine.id}><span>{machine.name}</span><span className={isRecentlyOnline(machine) ? 'text-success' : 'muted'}>{relativeTime(machine.last_seen_at)}</span></div>
              ))}
              {!machineList.length ? <span className="muted">尚未创建服务器</span> : null}
            </div>
          </Card>
        </div>
      </div>
    </>
  )
}
