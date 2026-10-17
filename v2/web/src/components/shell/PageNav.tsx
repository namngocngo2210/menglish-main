import { useState, type MouseEvent } from 'react'
import type { Tab } from '../../nav'
import { navigate } from '../../router'
import { Icon } from '../ui'

function go(e: MouseEvent, path: string) {
  if (e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) return
  e.preventDefault()
  navigate(path)
}

// Thanh tab của workspace (như WorkspaceTabs.vue): chỉ hiện khi workspace có từ 2 màn người dùng được xem.
export function WorkspaceTabs({ label, tabs }: { label: string; tabs: Tab[] }) {
  if (tabs.length < 2) return null
  return (
    <nav aria-label={label} className="mb-6 flex gap-1 overflow-x-auto border-b border-surface-container-highest">
      {tabs.map((tab) => (
        <a
          key={tab.path}
          href={tab.path}
          onClick={(e) => go(e, tab.path)}
          aria-current={tab.active ? 'page' : undefined}
          className="-mb-px border-b-2 border-transparent px-3 py-2 text-sm whitespace-nowrap text-on-surface-variant hover:text-on-surface aria-[current=page]:border-primary aria-[current=page]:font-medium aria-[current=page]:text-primary"
        >
          {tab.label}
        </a>
      ))}
    </nav>
  )
}

// Menu con trang Cài đặt (như SettingsNav.vue): cột trái ≥1024px, thu gọn thành nút mở danh sách ở màn nhỏ.
export function SettingsNav({ sections }: { sections: { label: string; items: Tab[] }[] }) {
  const [open, setOpen] = useState(false)
  const current = sections.flatMap((s) => s.items).find((i) => i.active)
  return (
    <nav aria-label="Cài đặt" className="shrink-0 lg:w-56">
      <button
        type="button"
        aria-expanded={open}
        onClick={() => setOpen((v) => !v)}
        className="flex w-full items-center justify-between gap-2 rounded-lg border border-outline-variant bg-surface px-4 py-2 text-sm font-medium text-on-surface lg:hidden"
      >
        <span className="flex min-w-0 items-center gap-2">
          <Icon name="settings" className="text-[20px] text-on-surface-variant" />
          <span className="truncate">Cài đặt{current ? ` · ${current.label}` : ''}</span>
        </span>
        <Icon name="expand_more" className={`text-[20px] transition-transform ${open ? 'rotate-180' : ''}`} />
      </button>
      <div
        className={`mt-2 space-y-4 rounded-xl border border-surface-container-highest bg-surface p-2 lg:sticky lg:top-20 lg:mt-0 lg:block ${open ? 'block' : 'hidden'}`}
      >
        <div className="hidden px-2 pt-1 text-base font-semibold text-on-surface lg:block">Cài đặt</div>
        {sections.map((section) => (
          <div key={section.label}>
            <div className="px-2 pb-1 text-xs font-semibold tracking-widest text-on-surface-variant uppercase">{section.label}</div>
            <ul className="space-y-0.5">
              {section.items.map((item) => (
                <li key={item.path}>
                  <a
                    href={item.path}
                    onClick={(e) => {
                      go(e, item.path)
                      setOpen(false)
                    }}
                    aria-current={item.active ? 'page' : undefined}
                    className={`block truncate rounded-lg px-2 py-1.5 text-sm transition-colors ${
                      item.active ? 'bg-primary/10 font-semibold text-primary' : 'text-on-surface-variant hover:bg-surface-container-low hover:text-primary'
                    }`}
                  >
                    {item.label}
                  </a>
                </li>
              ))}
            </ul>
          </div>
        ))}
      </div>
    </nav>
  )
}
