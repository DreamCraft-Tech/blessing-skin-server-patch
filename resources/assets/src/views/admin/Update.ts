interface UpdateEvent {
  type: 'status' | 'progress' | 'result'
  message?: string
  downloaded?: number
  total?: number
  code?: number
  data?: { review?: boolean }
}

export async function readEvents(
  response: Response,
  onEvent: (event: UpdateEvent) => void,
) {
  if (!response.ok) {
    const reasons: Record<number, string> = {
      403: '更新请求被拒绝（HTTP 403）。请确认超级管理员身份，并检查 CDN / OpenResty 访问限制。',
      419: '登录会话已过期，请刷新页面后重试。',
      429: '请求过于频繁，请稍后重试。',
      502: '网关连接失败，请检查 OpenResty 与 PHP 服务。',
      504: '网关等待超时，请检查服务端网络或使用 ZIP 运行包更新。',
    }
    throw new Error(
      reasons[response.status] || `下载请求失败：HTTP ${response.status}。`,
    )
  }
  if (!response.headers.get('Content-Type')?.includes('application/x-ndjson')) {
    throw new Error(
      '服务器返回了非预期响应，请刷新页面后重试并检查站点运行日志。',
    )
  }
  let completed = false
  let buffer = ''
  const consume = (text: string) => {
    buffer += text
    let index: number
    while ((index = buffer.indexOf('\n')) >= 0) {
      const line = buffer.slice(0, index).trim()
      buffer = buffer.slice(index + 1)
      if (!line) continue
      let event: UpdateEvent
      try {
        event = JSON.parse(line)
      } catch {
        throw new Error(
          '下载响应格式错误，请检查 CDN / OpenResty 是否改写了响应及站点运行日志。',
        )
      }
      onEvent(event)
      if (event.type === 'result') completed = true
    }
  }
  if (response.body?.getReader) {
    const reader = response.body.getReader()
    const decoder = new TextDecoder()
    try {
      while (true) {
        const { done, value } = await reader.read()
        if (done) break
        consume(decoder.decode(value, { stream: true }))
      }
      consume(decoder.decode())
    } finally {
      reader.releaseLock()
    }
  } else {
    consume(await response.text())
  }
  if (buffer.trim()) consume('\n')
  if (!completed) {
    throw new Error(
      '下载连接已中断，未收到完成结果。请重试，或使用 ZIP 运行包更新。',
    )
  }
}

function bytes(value: number) {
  return `${(value / 1048576).toFixed(2)} MiB`
}

export default async function handler(event: MouseEvent) {
  event.preventDefault()
  const button = event.currentTarget as HTMLButtonElement
  if (button.disabled) return
  const panel = document.querySelector<HTMLElement>('#update-download-status')!
  const label = panel.querySelector<HTMLElement>('[data-download-message]')!
  const bar = panel.querySelector<HTMLElement>('[role="progressbar"]')!
  panel.hidden = false
  panel.classList.remove('text-danger', 'text-success')
  button.disabled = true
  label.textContent = '正在连接更新源…'
  bar.style.width = '0%'
  bar.setAttribute('aria-valuenow', '0')
  bar.classList.add('progress-bar-striped', 'progress-bar-animated')
  try {
    const response = await fetch(`${blessing.base_url}/admin/update/download`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/x-ndjson',
        'X-CSRF-TOKEN':
          document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content || '',
      },
    })
    await readEvents(response, (item) => {
      if (item.type === 'progress') {
        const downloaded = Math.max(0, item.downloaded || 0)
        const total = Math.max(0, item.total || 0)
        const percent = total ? Math.min(100, (downloaded / total) * 100) : 0
        bar.style.width = `${total ? percent : 100}%`
        bar.setAttribute('aria-valuenow', String(Math.round(percent)))
        label.textContent = total
          ? `正在下载：${bytes(downloaded)} / ${bytes(
              total,
            )}（${percent.toFixed(1)}%）`
          : `正在下载：已接收 ${bytes(downloaded)}，服务器未提供总大小。`
      } else if (item.type === 'status') {
        label.textContent = item.message || ''
      } else {
        label.textContent = item.message || '更新请求失败，请重试。'
        panel.classList.add(item.code === 0 ? 'text-success' : 'text-danger')
        if (item.code === 0) {
          location.assign(
            `${blessing.base_url}${item.data?.review ? '/admin/update' : '/'}`,
          )
        }
      }
    })
  } catch (error) {
    label.textContent =
      error instanceof TypeError
        ? '与站点的连接失败或已中断。请检查浏览器网络、CDN 和 OpenResty 服务，重试或使用 ZIP 运行包更新。'
        : error instanceof Error
        ? error.message
        : '下载失败，请重试。'
    panel.classList.add('text-danger')
  } finally {
    button.disabled = false
    bar.classList.remove('progress-bar-striped', 'progress-bar-animated')
  }
}

document
  .querySelector<HTMLButtonElement>('#update')
  ?.addEventListener('click', handler)
