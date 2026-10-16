// Thành phần giao diện tối thiểu cho bước đăng nhập; bước 3 thay bằng design system (shadcn/ui).
import { useId, useState, type ButtonHTMLAttributes, type InputHTMLAttributes, type ReactNode } from 'react'

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
