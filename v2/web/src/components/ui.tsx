// Thành phần giao diện tối thiểu cho bước đăng nhập; bước 3 thay bằng design system (shadcn/ui).
import {
  useId,
  useState,
  type ButtonHTMLAttributes,
  type InputHTMLAttributes,
  type ReactNode,
  type SelectHTMLAttributes,
} from 'react'

export function Card({ title, children }: { title?: ReactNode; children: ReactNode }) {
  return (
    <section className="rounded-xl border border-surface-container-highest bg-surface p-6 shadow-sm">
      {title && <h2 className="mb-4 text-base font-semibold">{title}</h2>}
      {children}
    </section>
  )
}

export function Button({
  variant = 'primary',
  className = '',
  ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: 'primary' | 'secondary' | 'danger' }) {
  const styles = {
    primary: 'bg-primary text-white hover:bg-primary-hover disabled:opacity-50',
    secondary: 'border border-outline-variant bg-surface text-on-surface hover:bg-surface-container-low disabled:opacity-50',
    danger: 'border border-error/30 bg-surface text-error hover:bg-error-container disabled:opacity-50',
  }[variant]
  return (
    <button
      className={`inline-flex h-10 items-center justify-center rounded-lg px-4 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed ${styles} ${className}`}
      {...props}
    />
  )
}

export function Field({
  label,
  hint,
  ...props
}: InputHTMLAttributes<HTMLInputElement> & { label: string; hint?: string }) {
  const id = useId()
  return (
    <div className="space-y-1.5">
      <label htmlFor={id} className="block text-sm font-medium text-on-surface">
        {label}
      </label>
      <input
        id={id}
        className="block h-10 w-full rounded-lg border border-outline-variant px-3 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/25"
        {...props}
      />
      {hint && <p className="text-xs text-on-surface-variant">{hint}</p>}
    </div>
  )
}

export function PasswordField({ label, ...props }: InputHTMLAttributes<HTMLInputElement> & { label: string }) {
  const id = useId()
  const [visible, setVisible] = useState(false)
  return (
    <div className="space-y-1.5">
      <label htmlFor={id} className="block text-sm font-medium text-on-surface">
        {label}
      </label>
      <div className="relative">
        <input
          id={id}
          type={visible ? 'text' : 'password'}
          className="block h-10 w-full rounded-lg border border-outline-variant py-0 pr-16 pl-3 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/25"
          {...props}
        />
        <button
          type="button"
          onClick={() => setVisible((v) => !v)}
          className="absolute inset-y-0 right-0 px-3 text-xs font-medium text-on-surface-variant hover:text-on-surface"
          aria-label={visible ? 'Ẩn mật khẩu' : 'Hiện mật khẩu'}
        >
          {visible ? 'Ẩn' : 'Hiện'}
        </button>
      </div>
    </div>
  )
}

export function Alert({ tone = 'error', children }: { tone?: 'error' | 'info' | 'success'; children: ReactNode }) {
  const styles = {
    error: 'border-error/20 bg-error-container text-on-error-container',
    info: 'border-info/20 bg-info-container text-on-info-container',
    success: 'border-success/20 bg-success-container text-on-success-container',
  }[tone]
  return (
    <div role={tone === 'error' ? 'alert' : 'status'} className={`rounded-lg border px-3 py-2 text-sm ${styles}`}>
      {children}
    </div>
  )
}

// Giống GuestLayout của bản Laravel: logo, thẻ trắng ở giữa, dòng "Phát triển bởi".
export function GuestShell({ children }: { children: ReactNode }) {
  return (
    <main className="flex min-h-dvh flex-col items-center bg-background px-4 pt-6 text-on-surface sm:justify-center sm:pt-0">
      <img
        src="/images/menglish-logo.png"
        alt="MEnglish"
        width={80}
        height={80}
        className="size-20 rounded-xl bg-surface object-contain shadow-sm"
      />
      <div className="mt-6 w-full sm:max-w-md">{children}</div>
      <p className="py-6 text-center text-xs text-on-surface-variant">
        Phát triển bởi{' '}
        <a href="https://vmst.vn" target="_blank" rel="noopener noreferrer" className="font-semibold text-primary-hover hover:underline">
          VMST Media
        </a>
      </p>
    </main>
  )
}

export function Select({
  label,
  children,
  ...props
}: SelectHTMLAttributes<HTMLSelectElement> & { label: string; children: ReactNode }) {
  const id = useId()
  return (
    <div className="space-y-1.5">
      <label htmlFor={id} className="block text-sm font-medium text-on-surface">
        {label}
      </label>
      <select
        id={id}
        className="block h-10 w-full rounded-lg border border-outline-variant bg-surface px-3 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/25"
        {...props}
      >
        {children}
      </select>
    </div>
  )
}

export function Checkbox({
  label,
  hint,
  ...props
}: Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> & { label: ReactNode; hint?: string }) {
  return (
    <label className="flex items-start gap-2 text-sm" title={hint}>
      <input type="checkbox" className="mt-0.5 size-4 shrink-0 accent-primary" {...props} />
      <span>{label}</span>
    </label>
  )
}

export function Badge({ tone = 'neutral', children }: { tone?: 'neutral' | 'success' | 'error' | 'info'; children: ReactNode }) {
  const styles = {
    neutral: 'bg-surface-container-low text-on-surface-variant',
    success: 'bg-success-container text-on-success-container',
    error: 'bg-error-container text-on-error-container',
    info: 'bg-info-container text-on-info-container',
  }[tone]
  return <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${styles}`}>{children}</span>
}

// Hộp thoại đơn giản (lớp phủ + thẻ ở giữa); Esc hoặc bấm nền để đóng.
export function Dialog({ title, onClose, children }: { title: string; onClose: () => void; children: ReactNode }) {
  return (
    <div
      className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 px-4 py-10"
      onMouseDown={(e) => e.target === e.currentTarget && onClose()}
      onKeyDown={(e) => e.key === 'Escape' && onClose()}
    >
      <section
        role="dialog"
        aria-modal="true"
        aria-label={title}
        className="w-full max-w-lg rounded-xl bg-surface p-6 shadow-lg"
      >
        <div className="mb-4 flex items-center justify-between gap-4">
          <h2 className="text-base font-semibold">{title}</h2>
          <button type="button" onClick={onClose} className="text-sm text-on-surface-variant hover:text-on-surface" aria-label="Đóng">
            Đóng
          </button>
        </div>
        {children}
      </section>
    </div>
  )
}
