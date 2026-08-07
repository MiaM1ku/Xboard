import { useMemo, useState, type FormEvent } from 'react'
import useSWR from 'swr'
import { Activity, Copy, Eye, EyeOff, KeyRound, Link2, Plus, RefreshCw, Search, ShieldCheck, ShieldOff, Trash2, UsersRound } from 'lucide-react'
import { api } from '../lib/api'
import { copyText, formatBytes, formatDate } from '../lib/format'
import type { Group, Paginated, UserRecord } from '../types'
import { Badge, Button, Card, EmptyState, ErrorState, Field, Input, LoadingTable, Modal, PageHeader, Pagination, Select, useToast } from '../components/ui'
import { UserActivityModal } from '../components/UserActivityModal'

function datetimeValue(timestamp?: number | null) {
  if (!timestamp) return ''
  const date = new Date(timestamp * 1000)
  const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000)
  return local.toISOString().slice(0, 16)
}

function GrantModal({ user, groups, onClose, onSaved }: {
  user: UserRecord
  groups: Group[]
  onClose: () => void
  onSaved: () => void
}) {
  const [groupId, setGroupId] = useState(String(user.group_id || groups[0]?.id || ''))
  const [expiresAt, setExpiresAt] = useState(datetimeValue(user.expired_at))
  const [note, setNote] = useState(user.access_note || '')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const { notify } = useToast()

  async function submit(event: FormEvent) {
    event.preventDefault()
    if (!groupId) return
    setSaving(true)
    setError('')
    try {
      await api.adminPost('user/grant-access', {
        id: user.id,
        group_id: Number(groupId),
        expired_at: expiresAt ? Math.floor(new Date(expiresAt).getTime() / 1000) : null,
        note: note || null,
      })
      notify(`已授权 ${user.email}`)
      onSaved()
      onClose()
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : '授权失败')
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal open onClose={onClose} title="直接授权" description="自用模式按权限组直接授予节点访问，不创建套餐或订单。" footer={<><Button variant="secondary" onClick={onClose}>取消</Button><Button loading={saving} onClick={() => document.getElementById('grant-submit')?.click()}>保存授权</Button></>}>
      <form className="form-section" onSubmit={submit}>
        {error ? <div className="login-error" role="alert">{error}</div> : null}
        <Field label="用户"><Input value={user.email} disabled /></Field>
        <Field label="权限组" required><Select value={groupId} onChange={(event) => setGroupId(event.target.value)} required><option value="" disabled>选择权限组</option>{groups.map((group) => <option value={group.id} key={group.id}>{group.name}</option>)}</Select></Field>
        <Field label="到期时间" hint="留空表示长期有效。"><Input type="datetime-local" value={expiresAt} onChange={(event) => setExpiresAt(event.target.value)} /></Field>
        <Field label="授权备注"><Input value={note} onChange={(event) => setNote(event.target.value)} placeholder="例如：家庭成员 / 内部测试" maxLength={255} /></Field>
        <button id="grant-submit" type="submit" hidden />
      </form>
    </Modal>
  )
}

function CreateAccessUserModal({ groups, onClose, onCreated }: {
  groups: Group[]
  onClose: () => void
  onCreated: (user: UserRecord) => void
}) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [groupId, setGroupId] = useState(String(groups[0]?.id || ''))
  const [expiresAt, setExpiresAt] = useState('')
  const [note, setNote] = useState('')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const { notify } = useToast()

  async function submit(event: FormEvent) {
    event.preventDefault()
    if (!groupId) return
    setSaving(true)
    setError('')
    try {
      const user = await api.adminPost<UserRecord>('user/create-access', {
        email: email.trim(),
        password,
        group_id: Number(groupId),
        expired_at: expiresAt ? Math.floor(new Date(expiresAt).getTime() / 1000) : null,
        note: note || null,
      })
      notify(`已创建并授权 ${user.email}`)
      onCreated(user)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : '创建用户失败')
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal open onClose={onClose} title="添加用户" description="创建账号后立即按权限组授权，不生成套餐或订单。" footer={<><Button variant="secondary" onClick={onClose}>取消</Button><Button loading={saving} disabled={!groups.length} onClick={() => document.getElementById('create-access-user-submit')?.click()}><Plus size={15} />创建并授权</Button></>}>
      <form className="form-section" onSubmit={submit}>
        {error ? <div className="login-error" role="alert">{error}</div> : null}
        <div className="form-grid">
          <Field label="登录邮箱" required><Input type="email" value={email} onChange={(event) => setEmail(event.target.value)} placeholder="user@example.com" autoComplete="off" maxLength={64} required /></Field>
          <Field label="初始密码" hint="至少 8 个字符。" required><Input type="password" value={password} onChange={(event) => setPassword(event.target.value)} autoComplete="new-password" minLength={8} maxLength={128} required /></Field>
          <Field label="权限组" required><Select value={groupId} onChange={(event) => setGroupId(event.target.value)} required><option value="" disabled>{groups.length ? '选择权限组' : '暂无可用权限组'}</option>{groups.map((group) => <option value={group.id} key={group.id}>{group.name}</option>)}</Select></Field>
          <Field label="到期时间" hint="留空表示长期有效。"><Input type="datetime-local" value={expiresAt} onChange={(event) => setExpiresAt(event.target.value)} /></Field>
          <Field className="span-2" label="授权备注"><Input value={note} onChange={(event) => setNote(event.target.value)} placeholder="例如：家庭成员 / 内部测试" maxLength={255} /></Field>
        </div>
        <button id="create-access-user-submit" type="submit" hidden />
      </form>
    </Modal>
  )
}

function SubscriptionModal({ user, onClose }: { user: UserRecord; onClose: () => void }) {
  const [revealed, setRevealed] = useState(false)
  const [copying, setCopying] = useState(false)
  const { notify } = useToast()
  const subscriptionRequest = useSWR(['user-subscription', user.id], () => api.adminGet<UserRecord>(`user/subscription?id=${user.id}`))
  const subscription = subscriptionRequest.data

  async function copySubscription() {
    if (!subscription?.subscribe_url) return
    setCopying(true)
    try {
      await copyText(subscription.subscribe_url)
      notify('订阅链接已复制')
    } catch {
      notify('复制失败，请显示链接后手动复制', 'danger')
    } finally {
      setCopying(false)
    }
  }

  return (
    <Modal open onClose={onClose} title="用户订阅链接" description="订阅链接等同于访问凭据，请只发送给对应用户。" footer={<Button variant="secondary" onClick={onClose}>关闭</Button>}>
      {subscriptionRequest.error ? <ErrorState error={subscriptionRequest.error} retry={() => void subscriptionRequest.mutate()} /> : !subscription ? <LoadingTable rows={2} columns={1} /> : (
        <div className="form-section">
          <Field label="用户"><Input value={subscription.email} disabled /></Field>
          <div className="connection-methods">
            <Badge tone={subscription.access_enabled && !subscription.banned ? 'success' : 'warning'}>{subscription.access_enabled && !subscription.banned ? '已授权' : '当前不可用'}</Badge>
            {subscription.group?.name ? <Badge tone="info">{subscription.group.name}</Badge> : null}
            <span className="muted">{subscription.expired_at ? `到期：${formatDate(subscription.expired_at)}` : '长期有效'}</span>
          </div>
          <Field label="订阅链接" hint="默认隐藏；无需显示也可以直接复制。">
            <div className="subscription-link-row">
              <Input className="mono" type={revealed ? 'text' : 'password'} value={subscription.subscribe_url || ''} readOnly aria-label="订阅链接" />
              <Button variant="secondary" size="icon" onClick={() => setRevealed((value) => !value)} aria-label={revealed ? '隐藏订阅链接' : '显示订阅链接'} title={revealed ? '隐藏链接' : '显示链接'}>{revealed ? <EyeOff size={15} /> : <Eye size={15} />}</Button>
              <Button variant="secondary" loading={copying} onClick={() => void copySubscription()} disabled={!subscription.subscribe_url}><Copy size={15} />复制链接</Button>
            </div>
          </Field>
        </div>
      )}
    </Modal>
  )
}

export function UsersPage() {
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [createOpen, setCreateOpen] = useState(false)
  const [grantUser, setGrantUser] = useState<UserRecord | null>(null)
  const [activityUser, setActivityUser] = useState<UserRecord | null>(null)
  const [subscriptionUser, setSubscriptionUser] = useState<UserRecord | null>(null)
  const groupsRequest = useSWR('groups', () => api.adminGet<Group[]>('server/group/fetch'))
  const usersRequest = useSWR(['users', page, query], () => api.adminPost<Paginated<UserRecord>>('user/fetch', {
    current: page,
    pageSize: 20,
    ...(query ? { filter: [{ id: 'email', value: query, logic: 'and' }] } : {}),
  }))
  const { notify } = useToast()
  const response = usersRequest.data
  const users = response?.data || []

  const summary = useMemo(() => ({
    granted: users.filter((user) => Boolean(user.access_enabled)).length,
    admins: users.filter((user) => Boolean(user.is_admin)).length,
    devices: users.reduce((sum, user) => sum + Number(user.online_count || 0), 0),
  }), [users])

  async function revoke(user: UserRecord) {
    if (!window.confirm(`确认撤销 ${user.email} 的访问权限？其登录会话也会失效。`)) return
    try {
      await api.adminPost('user/revoke-access', { id: user.id })
      await usersRequest.mutate()
      notify('访问权限已撤销')
    } catch (reason) {
      notify(reason instanceof Error ? reason.message : '撤销失败', 'danger')
    }
  }

  async function remove(user: UserRecord) {
    if (!window.confirm(`确认永久删除用户 ${user.email}？该用户的授权、会话和历史流量记录都会删除，此操作不可恢复。`)) return
    try {
      await api.adminPost('user/destroy', { id: user.id })
      await usersRequest.mutate()
      notify('用户已删除')
    } catch (reason) {
      notify(reason instanceof Error ? reason.message : '删除用户失败', 'danger')
    }
  }

  function searchSubmit(event: FormEvent) {
    event.preventDefault()
    setPage(1)
    setQuery(search.trim())
  }

  return (
    <>
      <PageHeader title="用户授权" description="用户访问由权限组和直接授权状态控制，不再依赖套餐、订单或付费状态。" actions={<><Button onClick={() => setCreateOpen(true)}><Plus size={15} />添加用户</Button><Button variant="secondary" onClick={() => void usersRequest.mutate()}><RefreshCw size={15} />刷新</Button></>} />
      <div className="kpi-grid">
        <Card className="kpi-card"><div className="kpi-top"><span>当前页用户</span><span className="kpi-icon"><UsersRound size={16} /></span></div><div className="kpi-value">{users.length}</div><div className="kpi-hint">全库 {response?.total || 0} 个用户</div></Card>
        <Card className="kpi-card"><div className="kpi-top"><span>已授权</span><span className="kpi-icon"><ShieldCheck size={16} /></span></div><div className="kpi-value">{summary.granted}</div><div className="kpi-hint">当前页面统计</div></Card>
        <Card className="kpi-card"><div className="kpi-top"><span>在线设备</span><span className="kpi-icon"><KeyRound size={16} /></span></div><div className="kpi-value">{summary.devices}</div><div className="kpi-hint">来源聚合后的设备数</div></Card>
        <Card className="kpi-card"><div className="kpi-top"><span>管理员</span><span className="kpi-icon"><ShieldCheck size={16} /></span></div><div className="kpi-value">{summary.admins}</div><div className="kpi-hint">当前页面统计</div></Card>
      </div>
      <Card>
        <form className="card-toolbar" onSubmit={searchSubmit}>
          <div className="search-field" style={{ position: 'relative' }}><Search size={15} style={{ position: 'absolute', left: 11, top: 11, color: 'var(--muted-foreground)' }} /><Input style={{ paddingLeft: 34 }} value={search} onChange={(event) => setSearch(event.target.value)} placeholder="按邮箱搜索用户…" /></div>
          <Button variant="secondary" type="submit">搜索</Button>
          {query ? <Button variant="ghost" onClick={() => { setSearch(''); setQuery(''); setPage(1) }}>清除</Button> : null}
        </form>
        {usersRequest.error ? <ErrorState error={usersRequest.error} retry={() => void usersRequest.mutate()} /> : !response ? <LoadingTable rows={8} columns={7} /> : users.length ? (
          <>
            <div className="table-wrap">
              <table className="data-table">
                <thead><tr><th>ID</th><th>用户</th><th>访问状态</th><th>权限组</th><th>到期时间</th><th>流量</th><th style={{ textAlign: 'right' }}>操作</th></tr></thead>
                <tbody>{users.map((user) => (
                  <tr key={user.id}>
                    <td className="mono">#{user.id}</td>
                    <td><div className="table-primary"><strong>{user.email}</strong><span>{user.is_admin ? '管理员' : user.banned ? '已封禁' : `${user.online_count || 0} 台在线设备`}</span></div></td>
                    <td><div className="connection-methods">{user.banned ? <Badge tone="danger">已封禁</Badge> : <Badge tone={user.access_enabled ? 'success' : 'neutral'}>{user.access_enabled ? '已授权' : '未授权'}</Badge>}{user.is_admin ? <Badge tone="info">管理员</Badge> : null}</div></td>
                    <td>{user.group?.name || <span className="muted">未分组</span>}</td>
                    <td>{user.expired_at ? formatDate(user.expired_at) : '长期有效'}</td>
                    <td><div className="table-primary"><strong>{formatBytes((user.u || 0) + (user.d || 0))}</strong><span>/ {user.transfer_enable ? formatBytes(user.transfer_enable) : '不限'}</span></div></td>
                    <td><div className="table-actions"><Button variant="secondary" size="small" onClick={() => setSubscriptionUser(user)}><Link2 size={13} />订阅链接</Button><Button variant="secondary" size="small" onClick={() => setActivityUser(user)}><Activity size={13} />使用记录</Button><Button variant="secondary" size="small" onClick={() => setGrantUser(user)}><ShieldCheck size={13} />{user.access_enabled ? '修改授权' : '授权'}</Button>{user.access_enabled && !user.is_admin ? <Button variant="ghost" size="icon" onClick={() => void revoke(user)} aria-label={`撤销 ${user.email} 权限`}><ShieldOff size={15} /></Button> : null}{!user.is_admin ? <Button variant="ghost" size="icon" onClick={() => void remove(user)} aria-label={`删除 ${user.email}`} title="永久删除用户"><Trash2 className="text-danger" size={15} /></Button> : null}</div></td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
            <Pagination page={response.current_page} lastPage={response.last_page} total={response.total} onChange={setPage} />
          </>
        ) : <EmptyState icon={<UsersRound size={19} />} title="没有匹配的用户" description="可以直接创建账号并授予权限组。" action={!query ? <Button onClick={() => setCreateOpen(true)}><Plus size={15} />添加用户</Button> : undefined} />}
      </Card>
      {createOpen ? <CreateAccessUserModal groups={groupsRequest.data || []} onClose={() => setCreateOpen(false)} onCreated={(user) => { setCreateOpen(false); void usersRequest.mutate(); setSubscriptionUser(user) }} /> : null}
      {grantUser ? <GrantModal user={grantUser} groups={groupsRequest.data || []} onClose={() => setGrantUser(null)} onSaved={() => void usersRequest.mutate()} /> : null}
      {activityUser ? <UserActivityModal userId={activityUser.id} email={activityUser.email} onClose={() => setActivityUser(null)} /> : null}
      {subscriptionUser ? <SubscriptionModal user={subscriptionUser} onClose={() => setSubscriptionUser(null)} /> : null}
    </>
  )
}
