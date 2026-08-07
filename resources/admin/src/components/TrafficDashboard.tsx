import { useState } from 'react'
import useSWR from 'swr'
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { ArrowDown, ArrowUp, CalendarDays, Gauge, UsersRound } from 'lucide-react'
import { api } from '../lib/api'
import { formatBytes, formatDate } from '../lib/format'
import { Badge, Card, ErrorState, LoadingTable } from './ui'

interface TrafficTotal {
  upload: number
  download: number
  total: number
}

interface TrafficPoint extends TrafficTotal {
  label: string
  record_at: number
}

interface TrafficRank extends TrafficTotal {
  id: number
  name: string
}

interface TrafficDashboardData {
  generated_at: number
  source_freshness: { server_record_at: number; user_record_at: number }
  summary: { today: TrafficTotal; week: TrafficTotal; month: TrafficTotal; total: TrafficTotal }
  daily: TrafficPoint[]
  weekly: TrafficPoint[]
  ranks: {
    today: { users: TrafficRank[]; nodes: TrafficRank[] }
    week: { users: TrafficRank[]; nodes: TrafficRank[] }
  }
}

function TrafficKpi({ title, value, detail, icon }: { title: string; value: number; detail: string; icon: React.ReactNode }) {
  return <Card className="kpi-card"><div className="kpi-top"><span>{title}</span><span className="kpi-icon">{icon}</span></div><div className="kpi-value">{formatBytes(value)}</div><div className="kpi-hint">{detail}</div></Card>
}

function TrafficTooltip({ active, payload, label }: { active?: boolean; payload?: Array<{ dataKey?: string; value?: number; color?: string }>; label?: string }) {
  if (!active || !payload?.length) return null
  return <div className="chart-tooltip"><strong>{label}</strong>{payload.map((item) => <span key={item.dataKey} style={{ color: item.color }}>{item.dataKey === 'upload' ? '上传' : '下载'}：{formatBytes(item.value)}</span>)}</div>
}

function RankList({ title, icon, rows }: { title: string; icon: React.ReactNode; rows: TrafficRank[] }) {
  const max = Math.max(...rows.map((row) => Number(row.total)), 1)
  return <Card><div className="card-header"><div><h2>{title}</h2><p>统计周期内上行 + 下行流量</p></div>{icon}</div><div className="card-body traffic-rank-list">{rows.map((row, index) => <div className="traffic-rank-row" key={row.id}><span className="rank-index">{index + 1}</span><div><div className="rank-title"><strong title={row.name}>{row.name}</strong><span>{formatBytes(row.total)}</span></div><div className="rank-bar"><span style={{ width: `${Math.max(2, Number(row.total) / max * 100)}%` }} /></div><small><ArrowUp size={11} />{formatBytes(row.upload)} <ArrowDown size={11} />{formatBytes(row.download)}</small></div></div>)}{!rows.length ? <p className="muted">该周期暂无流量记录。</p> : null}</div></Card>
}

export function TrafficDashboard() {
  const request = useSWR('traffic-dashboard', () => api.adminGet<TrafficDashboardData>('stat/traffic-dashboard'), { refreshInterval: 60_000 })
  const [trendRange, setTrendRange] = useState<'daily' | 'weekly'>('daily')
  const [rankRange, setRankRange] = useState<'today' | 'week'>('week')
  const data = request.data

  if (request.error) return <ErrorState error={request.error} retry={() => void request.mutate()} />
  if (!data) return <LoadingTable rows={8} columns={4} />

  const points = trendRange === 'daily' ? data.daily : data.weekly
  const ranks = data.ranks[rankRange]
  const freshnessValues = [data.source_freshness.server_record_at, data.source_freshness.user_record_at].filter(Boolean)
  const freshness = freshnessValues.length ? Math.min(...freshnessValues) : 0

  return <section className="traffic-dashboard" aria-label="流量仪表盘">
    <div className="section-title-row"><div><h2>流量使用</h2><p>数据来自每日节点与用户统计表；最近记录 {freshness ? formatDate(freshness) : '暂无'}。</p></div><div className="period-control"><span>排行周期</span><div className="segmented period-switch" role="tablist" aria-label="排行统计周期"><button type="button" role="tab" aria-selected={rankRange === 'today'} className={rankRange === 'today' ? 'is-active' : ''} onClick={() => setRankRange('today')}>今日</button><button type="button" role="tab" aria-selected={rankRange === 'week'} className={rankRange === 'week' ? 'is-active' : ''} onClick={() => setRankRange('week')}>本周</button></div></div></div>
    <div className="kpi-grid">
      <TrafficKpi title="今日流量" value={data.summary.today.total} detail={`上传 ${formatBytes(data.summary.today.upload)} · 下载 ${formatBytes(data.summary.today.download)}`} icon={<CalendarDays size={16} />} />
      <TrafficKpi title="本周流量" value={data.summary.week.total} detail={`上传 ${formatBytes(data.summary.week.upload)} · 下载 ${formatBytes(data.summary.week.download)}`} icon={<Gauge size={16} />} />
      <TrafficKpi title="本月流量" value={data.summary.month.total} detail={`上传 ${formatBytes(data.summary.month.upload)} · 下载 ${formatBytes(data.summary.month.download)}`} icon={<CalendarDays size={16} />} />
      <TrafficKpi title="累计流量" value={data.summary.total.total} detail="数据库全部日统计记录" icon={<Gauge size={16} />} />
    </div>

    <Card className="traffic-chart-card">
      <div className="card-header"><div><h2>{trendRange === 'daily' ? '每日流量趋势' : '每周流量趋势'}</h2><p>{trendRange === 'daily' ? '最近 14 天' : '最近 12 周'}，上传与下载分开显示</p></div><div className="segmented" role="tablist" aria-label="流量趋势周期"><button type="button" className={trendRange === 'daily' ? 'is-active' : ''} aria-selected={trendRange === 'daily'} onClick={() => setTrendRange('daily')}>每日</button><button type="button" className={trendRange === 'weekly' ? 'is-active' : ''} aria-selected={trendRange === 'weekly'} onClick={() => setTrendRange('weekly')}>每周</button></div></div>
      <div className="traffic-chart" role="img" aria-label={`${trendRange === 'daily' ? '每日' : '每周'}上传下载流量柱状图`}>
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={points} margin={{ top: 8, right: 8, left: 4, bottom: 0 }}>
            <CartesianGrid stroke="var(--border)" vertical={false} />
            <XAxis dataKey="label" tickLine={false} axisLine={false} tick={{ fill: 'var(--muted-foreground)', fontSize: 11 }} />
            <YAxis tickLine={false} axisLine={false} width={64} tickFormatter={(value) => formatBytes(Number(value))} tick={{ fill: 'var(--muted-foreground)', fontSize: 10 }} />
            <Tooltip content={<TrafficTooltip />} cursor={{ fill: 'var(--muted)' }} />
            <Bar dataKey="upload" name="上传" stackId="traffic" fill="var(--info)" radius={[0, 0, 3, 3]} />
            <Bar dataKey="download" name="下载" stackId="traffic" fill="var(--primary)" radius={[3, 3, 0, 0]} />
          </BarChart>
        </ResponsiveContainer>
      </div>
    </Card>

    <div className="section-title-row rank-heading"><div><h2>{rankRange === 'today' ? '今日流量排行' : '本周流量排行'}</h2><p>当前查看：{rankRange === 'today' ? '今日 00:00 至现在' : '本周周一 00:00 至现在'}。周一时两个周期的数据可能完全相同。</p></div><Badge tone={rankRange === 'today' ? 'info' : 'success'}>{rankRange === 'today' ? '今日' : '本周'}</Badge></div>
    <div className="traffic-rank-grid" key={rankRange}><RankList title={`${rankRange === 'today' ? '今日' : '本周'}用户流量排行`} rows={ranks.users} icon={<UsersRound size={18} className="muted" />} /><RankList title={`${rankRange === 'today' ? '今日' : '本周'}节点流量排行`} rows={ranks.nodes} icon={<Gauge size={18} className="muted" />} /></div>
  </section>
}
