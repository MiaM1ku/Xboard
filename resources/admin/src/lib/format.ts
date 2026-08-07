export function formatBytes(value?: number | null): string {
  const bytes = Number(value || 0)
  if (!Number.isFinite(bytes) || bytes <= 0) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']
  const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1)
  return `${(bytes / 1024 ** index).toFixed(index > 2 ? 1 : 2)} ${units[index]}`
}

export function formatDate(value?: string | number | null): string {
  if (!value) return '从未'
  const numberValue = typeof value === 'number' && value < 10_000_000_000 ? value * 1000 : value
  const date = new Date(numberValue)
  if (Number.isNaN(date.getTime())) return '未知'
  return new Intl.DateTimeFormat('zh-CN', {
    year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit',
  }).format(date)
}

export function relativeTime(value?: string | number | null): string {
  if (!value) return '从未上报'
  const numberValue = typeof value === 'number' && value < 10_000_000_000 ? value * 1000 : value
  const seconds = Math.floor((Date.now() - new Date(numberValue).getTime()) / 1000)
  if (seconds < 60) return `${Math.max(0, seconds)} 秒前`
  if (seconds < 3600) return `${Math.floor(seconds / 60)} 分钟前`
  if (seconds < 86400) return `${Math.floor(seconds / 3600)} 小时前`
  return formatDate(value)
}

export function cn(...values: Array<string | false | null | undefined>): string {
  return values.filter(Boolean).join(' ')
}

export function copyText(value: string): Promise<void> {
  return navigator.clipboard.writeText(value)
}
