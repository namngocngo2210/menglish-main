// Lập / sửa phiếu thu học phí (Tuition/ReceiptForm.vue + Partials/ReceiptFields.vue + useReceiptForm.js).
// /tuition/receipts/new[?student_id=|tuition_id=] lập phiếu; /tuition/receipts/<id>/edit sửa phiếu nháp / bị trả về.
// Tiền phiếu tính theo sổ buổi: số buổi × đơn giá + học liệu + thi + khác − giảm + phụ thu; server tính lại và từ chối
// nếu lệch. Phiếu không tự duyệt khi lập: "Gửi duyệt" chuyển Admin duyệt; "Lưu nháp" để sửa tiếp.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button, Card, Checkbox, Field, Select } from '../../components/ui'
import { navigate, usePath, useQueryParam } from '../../router'
import { tuitionApi, type ReceiptDetail, type ReceiptForm, type ReceiptInput } from './api'
import { Tag } from './components'
import { calculate, formatMoney, promotionApplies, promotionDiscount, promotionLabel, toInt, vietQrUrl } from './logic'

export function ReceiptsRoute({ me }: { me: Me }) {
  const path = usePath()
  const m = path.match(/^\/tuition\/receipts\/([0-9a-f-]{36})\/edit$/)
  if (m) return <ReceiptFormPage me={me} receiptId={m[1]} />
  return <ReceiptFormPage me={me} />
}

function ReceiptFormPage({ receiptId }: { me: Me; receiptId?: string }) {
  const studentParam = useQueryParam('student_id')
  const tuitionParam = useQueryParam('tuition_id')
  const form = useQuery({
    queryKey: ['tuition', 'form', receiptId ?? '', studentParam, tuitionParam],
    queryFn: () => tuitionApi.form({ receipt_id: receiptId, student_id: studentParam, tuition_id: tuitionParam }),
  })
  const title = receiptId ? 'Sửa phiếu thu' : 'Lập phiếu thu'
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold">{title}</h1>
        <p className="text-sm text-on-surface-variant">
          Phiếu thu không tự duyệt: gửi duyệt để Admin đối chiếu chứng từ, cấp số hóa đơn và ghi nhận vào công nợ.
        </p>
      </div>
      {form.isError && <Alert>{errorMessage(form.error)}</Alert>}
      {form.data && !form.data.student && <StudentPicker />}
      {form.data?.student && <ReceiptFields key={`${form.data.student.id}-${receiptId ?? ''}`} data={form.data} receiptId={receiptId} />}
    </div>
  )
}

function StudentPicker() {
  const [q, setQ] = useState('')
  const [term, setTerm] = useState('')
  const opts = useQuery({ queryKey: ['tuition', 'student-options', term], queryFn: () => tuitionApi.studentOptions(term) })
  return (
    <Card title="1. Chọn học viên">
      <form
        className="flex flex-wrap items-end gap-3"
        onSubmit={(e) => {
          e.preventDefault()
          setTerm(q)
        }}
      >
        <div className="min-w-64 flex-1">
          <Field label="Tìm học viên" placeholder="Họ tên, mã học viên hoặc số điện thoại..." value={q} onChange={(e) => setQ(e.target.value)} />
        </div>
        <Button type="submit" variant="secondary">
          Tìm
        </Button>
      </form>
      {opts.isError && <Alert>{errorMessage(opts.error)}</Alert>}
      <ul className="mt-4 divide-y divide-surface-container-highest">
        {opts.data?.students.map((s) => (
          <li key={s.id}>
            <button
              type="button"
              className="flex w-full flex-wrap items-center justify-between gap-2 py-2 text-left text-sm hover:bg-surface-container-low"
              onClick={() => navigate(`/tuition/receipts/new?student_id=${s.id}`)}
            >
              <span>
                <span className="font-medium">{s.name}</span> <span className="font-mono text-xs text-on-surface-variant">{s.code}</span>
              </span>
              <span className="text-xs text-on-surface-variant">
                {s.class_name ?? 'Chưa xếp lớp'} · {s.branch_name ?? '—'} · {s.phone}
              </span>
            </button>
          </li>
        ))}
        {opts.data && opts.data.students.length === 0 && <li className="py-2 text-sm text-on-surface-variant">Không tìm thấy học viên nào.</li>}
      </ul>
    </Card>
  )
}

type Line = { id: string; quantity: string }

function initialLines(editing: ReceiptDetail | null): Line[] {
  return (editing?.surcharge_items ?? []).map((l) => ({ id: l.id, quantity: String(l.quantity) }))
}

function ReceiptFields({ data, receiptId }: { data: ReceiptForm; receiptId?: string }) {
  const qc = useQueryClient()
  const student = data.student!
  const editing = data.editing
  const es = editing?.session ?? null
  const [tuitionId, setTuitionId] = useState(data.selected_tuition_id ?? '')
  const [skipTuition, setSkipTuition] = useState(!!editing && !editing.student_tuition_id && !(es && es.session_count > 0))
  const [sessionCount, setSessionCount] = useState<string | null>(es ? String(es.session_count) : null)
  const [promotionId, setPromotionId] = useState(editing?.promotion_id ?? '')
  const [discountAmount, setDiscountAmount] = useState(editing && !editing.promotion_id ? String(editing.discount_amount) : '0')
  const [discountReason, setDiscountReason] = useState(editing?.discount_reason ?? '')
  const [examFee, setExamFee] = useState(es ? String(es.exam_fee) : '0')
  const [otherFee, setOtherFee] = useState(es ? String(es.other_fee) : '0')
  const [otherFeeReason, setOtherFeeReason] = useState(es?.other_fee_reason ?? '')
  const [lines, setLines] = useState<Line[]>(initialLines(editing))
  const [pick, setPick] = useState('')
  const itemById = (id: string) => data.items.find((i) => i.id === id) ?? null
  const linesTotal = lines.reduce((s, l) => s + (itemById(l.id)?.price ?? 0) * toInt(l.quantity), 0)
  const [extra, setExtra] = useState(es ? String(es.extra) : editing ? String(Math.max(0, editing.surcharge_amount - linesTotal)) : '0')
  const [surchargeReason, setSurchargeReason] = useState(editing?.surcharge_reason ?? '')
  const [method, setMethod] = useState(editing?.payment_method === 'cash' ? 'cash' : 'transfer')
  const [transactionCode, setTransactionCode] = useState(editing?.transaction_code ?? '')
  const [paperNumber, setPaperNumber] = useState(editing?.paper_invoice_number ?? '')
  const [payerName, setPayerName] = useState(editing?.payer_name ?? student.parent_name)
  const [payerPhone, setPayerPhone] = useState(editing?.payer_phone ?? student.parent_phone)
  const [isVat, setIsVat] = useState(!!editing?.is_vat_invoice)
  const [notes, setNotes] = useState(editing?.notes ?? '')
  const [proofUrl, setProofUrl] = useState(editing?.proof_url ?? '')
  const [error, setError] = useState('')

  const tuition = data.tuitions.find((t) => t.id === tuitionId) ?? null
  const quote = skipTuition ? null : (tuition?.quote ?? student.quote)
  const canSessions = !!quote && quote.mode !== 'none'
  const maxSessions = canSessions ? quote.max_sessions : 0
  // Đổi khoản học phí → số buổi về mặc định "Buổi cần thu" (phiếu đang sửa giữ số buổi đã lưu).
  useEffect(() => {
    setSessionCount((cur) => (cur !== null && es ? cur : quote && quote.mode !== 'none' ? String(quote.suggested) : '0'))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tuitionId, skipTuition])
  const sessions = canSessions ? Math.min(maxSessions, Math.max(0, toInt(sessionCount ?? '0'))) : 0
  const base = calculate(quote, { sessions, itemsTotal: 0, examFee: 0, otherFee: 0, extra: 0, discount: 0 }).sessionValue

  const scope = tuition ? { branch: tuition.branch_id, course: tuition.course_id } : { branch: student.branch_id, course: student.course_id }
  const promotions = useMemo(
    () => (skipTuition || sessions <= 0 ? [] : data.promotions.filter((p) => promotionApplies(p, scope.branch, scope.course) || p.id === editing?.promotion_id)),
    [skipTuition, sessions, data.promotions, scope.branch, scope.course, editing?.promotion_id],
  )
  const promotion = promotions.find((p) => p.id === promotionId) ?? null
  const discount = sessions <= 0 ? 0 : Math.min(base, promotion ? promotionDiscount(promotion, base) : Math.max(0, toInt(discountAmount)))
  const needsDiscountReason = !promotion && discount > 0
  const calc = calculate(quote, { sessions, itemsTotal: linesTotal, examFee: toInt(examFee), otherFee: toInt(otherFee), extra: toInt(extra), discount })

  const branchId = tuition?.branch_id ?? student.branch_id
  const paperMode = method === 'cash' && (!!editing?.issued_paper_invoice || !!data.paper_invoice_next)
  const paperNo = paperMode ? (editing?.issued_paper_invoice ?? data.paper_invoice_next) : null
  const proofRequired = method === 'transfer' || paperMode
  const bank = tuition?.bank ?? data.default_bank
  const memo = tuition?.transfer_memo || student.transfer_memo

  const save = useMutation({
    mutationFn: (action: 'draft' | 'submit') => {
      const body: ReceiptInput = {
        student_id: student.id,
        student_tuition_id: skipTuition ? null : tuitionId || null,
        session_count: sessions,
        exam_fee: calc.examFee,
        other_fee: calc.otherFee,
        other_fee_reason: otherFeeReason,
        discount_amount: calc.discount,
        promotion_id: promotion && calc.discount > 0 ? promotion.id : null,
        discount_reason: discountReason,
        surcharge_amount: calc.extra,
        surcharge_reason: surchargeReason,
        amount: calc.amount,
        tuition_amount: calc.totalDue,
        payment_method: method,
        transaction_code: transactionCode,
        paper_invoice_number: paperMode ? undefined : paperNumber,
        expected_paper_invoice_number: paperMode && !editing?.issued_paper_invoice ? paperNo : null,
        payer_name: payerName,
        payer_phone: payerPhone,
        is_vat_invoice: isVat,
        notes,
        collected_items: lines.map((l) => ({ id: l.id, quantity: Math.max(1, toInt(l.quantity)) })),
        proof_url: proofUrl.trim() || null,
        remove_proof: !!editing?.proof_url && !proofUrl.trim(),
        submit_action: action,
      }
      if (receiptId) {
        delete body.student_id
        delete body.student_tuition_id
      }
      return receiptId ? tuitionApi.update(receiptId, body) : tuitionApi.create(body)
    },
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['tuition'] })
      navigate(`/tuition/history?search=${encodeURIComponent(r.code)}&notice=${encodeURIComponent(r.message)}`)
    },
    onError: (e) => setError(errorMessage(e)),
  })

  const valid =
    calc.amount >= 1000 &&
    (calc.extra <= 0 || surchargeReason.trim() !== '') &&
    (calc.otherFee <= 0 || otherFeeReason.trim() !== '') &&
    (!needsDiscountReason || discountReason.trim() !== '') &&
    lines.every((l) => toInt(l.quantity) >= 1)

  const submit = (e: FormEvent, action: 'draft' | 'submit') => {
    e.preventDefault()
    setError('')
    save.mutate(action)
  }

  return (
    <form className="grid gap-6 lg:grid-cols-[1fr_340px]" onSubmit={(e) => submit(e, 'submit')}>
      <div className="space-y-6">
        {data.recent_rejection && (
          <Alert>
            Phiếu {data.recent_rejection.code} bị trả về: {data.recent_rejection.rejection_reason ?? '—'}
            {data.recent_rejection.can_edit && !receiptId && (
              <>
                {' '}
                <button type="button" className="font-semibold underline" onClick={() => navigate(`/tuition/receipts/${data.recent_rejection!.id}/edit`)}>
                  Sửa và gửi lại phiếu này
                </button>
              </>
            )}
          </Alert>
        )}
        {student.is_deferred && <Alert tone="info">Học viên đang bảo lưu.</Alert>}
        <Card title="1. Học viên & khoản học phí">
          <div className="mb-3 flex flex-wrap items-center justify-between gap-2 text-sm">
            <div>
              <div className="font-semibold">
                {student.name} <span className="font-mono text-xs text-on-surface-variant">{student.code}</span>
              </div>
              <div className="text-xs text-on-surface-variant">
                {student.class_name} · {student.branch_name} · {student.phone}
              </div>
            </div>
            {!receiptId && (
              <Button type="button" variant="secondary" className="h-8 px-2" onClick={() => navigate('/tuition/receipts/new')}>
                Đổi học viên
              </Button>
            )}
          </div>
          <div className="grid gap-3 sm:grid-cols-2">
            <Select
              label="Khoản học phí đang nợ (tùy chọn)"
              value={tuitionId}
              disabled={!!receiptId || skipTuition}
              onChange={(e) => {
                setTuitionId(e.target.value)
                setSessionCount(null)
              }}
            >
              <option value="">— Thu theo sổ buổi của học viên —</option>
              {data.tuitions.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.fee_label} · còn nợ {formatMoney(t.debt_amount)}
                </option>
              ))}
            </Select>
            <div className="flex items-end">
              <Checkbox
                label="Chỉ thu phụ thu (không thu học phí)"
                checked={skipTuition}
                disabled={!!receiptId}
                onChange={(e) => {
                  setSkipTuition(e.target.checked)
                  setSessionCount(null)
                }}
              />
            </div>
          </div>
          {tuition?.class_changed && (
            <p className="mt-2 text-xs text-warning">
              Khoản này của lớp {tuition.class_name}; học viên đang học lớp {tuition.current_class_name ?? '—'}.
            </p>
          )}
          {quote && (
            <div className="mt-4 rounded-lg bg-surface-container-low p-3 text-sm">
              <div className="mb-2 font-medium">Sổ buổi{quote.class_name ? ` · lớp ${quote.class_name}` : ''}</div>
              {quote.mode === 'none' ? (
                <p className="text-on-surface-variant">Học viên chưa xếp lớp và chưa có khoản học phí nên chưa thu theo buổi được.</p>
              ) : (
                <dl className="grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-4">
                  <Fact label="Đã đóng" value={`${quote.paid} buổi`} />
                  <Fact label="Đã học" value={`${quote.used} buổi`} />
                  <Fact label="Tồn" value={`${quote.balance} buổi`} />
                  <Fact label="Khóa" value={`${quote.course_sessions} buổi (đã diễn ra ${quote.held})`} />
                  <Fact label="Còn lại của khóa" value={`${quote.remaining} buổi`} />
                  <Fact label="Buổi cần thu" value={`${quote.needed} buổi`} />
                  <Fact label="Đơn giá" value={formatMoney(quote.unit_price)} />
                  {quote.mode === 'contract' && <Fact label="Hợp đồng còn" value={`${quote.contract_remaining_sessions ?? 0} buổi`} />}
                </dl>
              )}
            </div>
          )}
        </Card>

        {!skipTuition && canSessions && (
          <Card title="2. Học phí theo buổi">
            <div className="grid gap-3 sm:grid-cols-2">
              <Field
                label="Số buổi thu đợt này"
                type="number"
                min={0}
                max={maxSessions}
                value={sessionCount ?? ''}
                onChange={(e) => setSessionCount(e.target.value)}
                hint={
                  quote.mode === 'contract'
                    ? `Tối đa ${maxSessions} buổi chưa đóng của khoản học phí. Thu ít hơn để đóng từng phần.`
                    : `Tối đa ${maxSessions} buổi (Buổi cần thu). Thu ít hơn để đóng từng phần.`
                }
              />
              <div className="space-y-1.5 text-sm">
                <span className="block font-medium">Tiền học phí theo buổi</span>
                <div className="flex h-10 items-center font-mono font-semibold">{formatMoney(calc.sessionValue)}</div>
              </div>
              {sessions > 0 && (
                <Select label="Ưu đãi" value={promotion?.id ?? ''} onChange={(e) => setPromotionId(e.target.value)}>
                  <option value="">— Không theo ưu đãi có sẵn —</option>
                  {promotions.map((p) => (
                    <option key={p.id} value={p.id}>
                      {promotionLabel(p)}
                    </option>
                  ))}
                </Select>
              )}
              {sessions > 0 && !promotion && (
                <Field label="Giảm trừ nhập tay (VNĐ)" type="number" min={0} value={discountAmount} onChange={(e) => setDiscountAmount(e.target.value)} />
              )}
              {sessions > 0 && (
                <Field
                  label={needsDiscountReason ? 'Lý do giảm (bắt buộc)' : 'Lý do giảm'}
                  value={discountReason}
                  required={needsDiscountReason}
                  onChange={(e) => setDiscountReason(e.target.value)}
                />
              )}
            </div>
            {calc.feeDue > 0 && <p className="mt-2 text-sm text-on-surface-variant">Học liệu còn nợ lúc chốt: {formatMoney(calc.feeDue)}</p>}
          </Card>
        )}

        <Card title="3. Học liệu, phí thi & phụ thu">
          <div className="flex flex-wrap items-end gap-2">
            <div className="min-w-64 flex-1">
              <Select label="Học liệu: sách / hàng hóa giao kèm phiếu" value={pick} onChange={(e) => setPick(e.target.value)}>
                <option value="">— Chọn hàng hóa —</option>
                {data.items.map((i) => (
                  <option key={i.id} value={i.id}>
                    {i.name} · {formatMoney(i.price)} · tồn {i.stock}
                  </option>
                ))}
              </Select>
            </div>
            <Button
              type="button"
              variant="secondary"
              disabled={!pick}
              onClick={() => {
                setLines((ls) => (ls.some((l) => l.id === pick) ? ls.map((l) => (l.id === pick ? { ...l, quantity: String(toInt(l.quantity) + 1) } : l)) : [...ls, { id: pick, quantity: '1' }]))
                setPick('')
              }}
            >
              Thêm
            </Button>
          </div>
          {lines.length > 0 && (
            <ul className="mt-3 space-y-2">
              {lines.map((l, i) => {
                const item = itemById(l.id)
                return (
                  <li key={l.id} className="flex flex-wrap items-center gap-2 text-sm">
                    <span className="flex-1">{item?.name ?? 'Hàng hóa đã ngừng bán'}</span>
                    <input
                      aria-label={`Số lượng ${item?.name ?? ''}`}
                      type="number"
                      min={1}
                      className="h-9 w-20 rounded-lg border border-outline-variant px-2"
                      value={l.quantity}
                      onChange={(e) => setLines((ls) => ls.map((x, j) => (j === i ? { ...x, quantity: e.target.value } : x)))}
                    />
                    <span className="w-28 text-right font-mono">{formatMoney((item?.price ?? 0) * toInt(l.quantity))}</span>
                    <button type="button" className="text-xs text-error" onClick={() => setLines((ls) => ls.filter((_, j) => j !== i))}>
                      Bỏ
                    </button>
                  </li>
                )
              })}
            </ul>
          )}
          <div className="mt-4 grid gap-3 sm:grid-cols-3">
            <Field label="Phí thi (VNĐ)" type="number" min={0} value={examFee} onChange={(e) => setExamFee(e.target.value)} />
            <Field label="Khoản thu khác (VNĐ)" type="number" min={0} value={otherFee} onChange={(e) => setOtherFee(e.target.value)} />
            <Field label="Nội dung khoản khác" value={otherFeeReason} required={toInt(otherFee) > 0} onChange={(e) => setOtherFeeReason(e.target.value)} />
            <Field label="Phụ thu (VNĐ)" type="number" min={0} value={extra} hint="Cộng sau giảm trừ, ngoài tổng phải thu." onChange={(e) => setExtra(e.target.value)} />
            <div className="sm:col-span-2">
              <Field label="Lý do phụ thu" value={surchargeReason} required={toInt(extra) > 0} onChange={(e) => setSurchargeReason(e.target.value)} />
            </div>
          </div>
        </Card>

        <Card title="4. Hình thức thu & chứng từ">
          <div className="mb-4 flex gap-2" role="radiogroup" aria-label="Hình thức thu">
            {data.methods.map((m) => (
              <button
                key={m.value}
                type="button"
                role="radio"
                aria-checked={method === m.value}
                onClick={() => setMethod(m.value)}
                className={`rounded-lg px-3 py-2 text-sm font-medium ${method === m.value ? 'bg-primary text-white' : 'bg-surface-container-low text-on-surface-variant hover:bg-surface-container-high'}`}
              >
                {m.label}
              </button>
            ))}
          </div>
          <div className="grid gap-3 sm:grid-cols-2">
            {method === 'transfer' && (
              <>
                <Field
                  label="Mã tham chiếu / Mã giao dịch ngân hàng (nếu có)"
                  placeholder="Ví dụ: FT232981354789..."
                  value={transactionCode}
                  onChange={(e) => setTransactionCode(e.target.value)}
                />
                <div className="space-y-1.5 text-sm">
                  <span className="block font-medium">Nội dung chuyển khoản</span>
                  <div className="flex h-10 items-center font-mono">{memo || '—'}</div>
                </div>
              </>
            )}
            {method === 'cash' &&
              (paperMode ? (
                <Alert tone="info">
                  Chi nhánh dùng cuốn hóa đơn giấy: ghi số <strong className="font-mono">{paperNo}</strong> lên hóa đơn giấy cùng nội dung thu, chụp ảnh tải
                  lên làm minh chứng rồi gửi duyệt.
                </Alert>
              ) : (
                <Field
                  label="Số hóa đơn giấy thu tiền mặt (bắt buộc khi gửi duyệt)"
                  hint="Chi nhánh chưa cấu hình dải hóa đơn giấy: xuất hóa đơn giấy cho khách rồi ghi số vào đây."
                  value={paperNumber}
                  onChange={(e) => setPaperNumber(e.target.value)}
                />
              ))}
            <Field label="Người nộp tiền" value={payerName} onChange={(e) => setPayerName(e.target.value)} />
            <Field label="Số điện thoại" type="tel" value={payerPhone} onChange={(e) => setPayerPhone(e.target.value)} />
            <div className="sm:col-span-2">
              {/* TODO(files): tải ảnh minh chứng lên phân hệ tệp; tạm nhập đường dẫn ảnh (Drive / Zalo...) hoặc ghi chú. */}
              <Field
                label={proofRequired ? 'Minh chứng (bắt buộc khi gửi duyệt)' : 'Minh chứng'}
                hint="Đường dẫn ảnh ủy nhiệm chi / ảnh hóa đơn giấy (tải tệp trực tiếp sẽ có khi phân hệ tệp sẵn sàng)."
                value={proofUrl}
                onChange={(e) => setProofUrl(e.target.value)}
              />
            </div>
            <Checkbox label="Yêu cầu xuất hóa đơn đỏ (VAT)" checked={isVat} onChange={(e) => setIsVat(e.target.checked)} />
            <div className="sm:col-span-2">
              <label className="block space-y-1.5 text-sm">
                <span className="block font-medium">Ghi chú nội bộ</span>
                <textarea
                  rows={3}
                  className="block w-full rounded-lg border border-outline-variant px-3 py-2 text-sm outline-none focus:border-primary"
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  placeholder="Ghi chú cho bộ phận kế toán và quản lý lớp..."
                />
              </label>
            </div>
          </div>
        </Card>
      </div>

      <aside className="space-y-4 lg:sticky lg:top-4 lg:self-start">
        <Card title="Tổng phiếu">
          <dl className="space-y-1 text-sm">
            <Sum label={`Học phí ${calc.sessionCount} buổi`} value={calc.sessionValue} />
            {calc.materialFee > 0 && <Sum label="Học liệu" value={calc.materialFee} />}
            {calc.examFee > 0 && <Sum label="Phí thi" value={calc.examFee} />}
            {calc.otherFee > 0 && <Sum label="Khoản khác" value={calc.otherFee} />}
            {calc.discount > 0 && <Sum label="Giảm trừ" value={-calc.discount} />}
            <Sum label="Tổng phải thu" value={calc.totalDue} />
            {calc.extra > 0 && <Sum label="Phụ thu" value={calc.extra} />}
          </dl>
          <div className="mt-3 flex items-center justify-between border-t border-surface-container-highest pt-3">
            <span className="text-sm font-medium">Tổng thu</span>
            <span className="font-mono text-lg font-semibold text-primary">{formatMoney(calc.amount)}</span>
          </div>
          {editing && (
            <p className="mt-2 text-xs text-on-surface-variant">
              Đang sửa phiếu <span className="font-mono">{editing.code}</span> <Tag tone={editing.status_color}>{editing.status_label}</Tag>
            </p>
          )}
          {branchId === null && <p className="mt-2 text-xs text-warning">Học viên chưa gắn chi nhánh.</p>}
        </Card>
        {method === 'transfer' && bank && calc.amount > 0 && (
          <Card title="VietQR">
            <img src={vietQrUrl(bank, calc.amount, memo)} alt="Mã VietQR chuyển khoản" className="mx-auto w-56" />
            <p className="mt-2 text-center text-xs text-on-surface-variant">
              {bank.bank_name} · {bank.account_number} · {bank.account_holder}
            </p>
          </Card>
        )}
        {error && <Alert>{error}</Alert>}
        <div className="flex flex-col gap-2">
          <Button type="submit" disabled={!valid || save.isPending}>
            Gửi duyệt
          </Button>
          <Button type="button" variant="secondary" disabled={save.isPending || calc.amount < 1000} onClick={(e) => submit(e, 'draft')}>
            Lưu nháp
          </Button>
        </div>
      </aside>
    </form>
  )
}

function Fact({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-xs text-on-surface-variant">{label}</dt>
      <dd className="font-medium">{value}</dd>
    </div>
  )
}

function Sum({ label, value }: { label: string; value: number }) {
  return (
    <div className="flex justify-between gap-3">
      <dt className="text-on-surface-variant">{label}</dt>
      <dd className="font-mono">{formatMoney(value)}</dd>
    </div>
  )
}
