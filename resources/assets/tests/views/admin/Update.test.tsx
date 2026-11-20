import { TextDecoder, TextEncoder } from 'util'
import handler, { readEvents } from '@/views/admin/Update'

function response(text: string, status = 200): Response {
  return {
    ok: status === 200,
    status,
    headers: { get: () => 'application/x-ndjson; charset=UTF-8' },
    text: async () => text,
  } as unknown as Response
}

beforeEach(() => {
  document.body.innerHTML = `<button id="update">更新</button><div id="update-download-status" hidden><p data-download-message></p><div role="progressbar"></div></div>`
})

test('reports actual progress and a useful server failure in the page', async () => {
  window.fetch = jest
    .fn()
    .mockResolvedValue(
      response(
        [
          JSON.stringify({
            type: 'progress',
            downloaded: 524288,
            total: 1048576,
          }),
          JSON.stringify({
            type: 'result',
            code: 1,
            message: '下载失败：HTTP 503',
          }),
        ].join('\n'),
      ),
    )
  const button = document.querySelector<HTMLButtonElement>('#update')!
  await handler({
    preventDefault: jest.fn(),
    currentTarget: button,
  } as unknown as MouseEvent)
  expect(document.querySelector('[data-download-message]')).toHaveTextContent(
    'HTTP 503',
  )
  expect(document.querySelector('[role="progressbar"]')).toHaveAttribute(
    'aria-valuenow',
    '50',
  )
  expect(button).not.toBeDisabled()
  expect(document.querySelector('#update-download-status')).toHaveClass(
    'text-danger',
  )
})

test.each([403, 419, 429, 502, 504])(
  'explains HTTP %s and restores retry button',
  async (status) => {
    window.fetch = jest.fn().mockResolvedValue(response('', status))
    const button = document.querySelector<HTMLButtonElement>('#update')!
    await handler({
      preventDefault: jest.fn(),
      currentTarget: button,
    } as unknown as MouseEvent)
    expect(
      document.querySelector('[data-download-message]')?.textContent,
    ).not.toBe('')
    expect(document.querySelector('#update-download-status')).toHaveClass(
      'text-danger',
    )
    expect(button).not.toBeDisabled()
  },
)

test('does not treat an interrupted stream as success', async () => {
  await expect(
    readEvents(
      response('{"type":"progress","downloaded":20,"total":0}\n'),
      jest.fn(),
    ),
  ).rejects.toThrow('连接已中断')
})

test('restores retry after browser network failure', async () => {
  window.fetch = jest.fn().mockRejectedValue(new TypeError('Load failed'))
  const button = document.querySelector<HTMLButtonElement>('#update')!
  await handler({
    preventDefault: jest.fn(),
    currentTarget: button,
  } as unknown as MouseEvent)
  expect(document.querySelector('[data-download-message]')).toHaveTextContent(
    '连接失败或已中断',
  )
  expect(button).not.toBeDisabled()
})

test('parses streamed events across UTF-8 and line boundaries', async () => {
  Object.assign(window, { TextDecoder })
  const data = new TextEncoder().encode(
    '{"type":"status","message":"正在下载"}\n{"type":"result","code":1,"message":"失败"}\n',
  )
  const read = jest
    .fn()
    .mockResolvedValueOnce({ done: false, value: data.slice(0, 30) })
    .mockResolvedValueOnce({ done: false, value: data.slice(30, 35) })
    .mockResolvedValueOnce({ done: false, value: data.slice(35) })
    .mockResolvedValueOnce({ done: true })
  const releaseLock = jest.fn()
  const streamed = response('')
  Object.defineProperty(streamed, 'body', {
    value: { getReader: () => ({ read, releaseLock }) },
  })
  const events = jest.fn()
  await readEvents(streamed, events)
  expect(events).toHaveBeenNthCalledWith(1, {
    type: 'status',
    message: '正在下载',
  })
  expect(events).toHaveBeenNthCalledWith(2, {
    type: 'result',
    code: 1,
    message: '失败',
  })
  expect(releaseLock).toHaveBeenCalled()
})
