import type { Circle, Map as LeafletMap, Marker } from 'leaflet'
import { useEffect, useRef, useState } from 'react'
import { api, ApiError } from '../../api'
import type { AddressPlace } from '../../api'
import { Alert, Button } from '../../components/ui'
import { toPoint } from '../../lib/geo'

// Bản đồ chọn tọa độ chấm công của chi nhánh (OpenStreetMap + Leaflet, không cần API key), giống BranchLocationMap.vue.
// Tìm theo địa chỉ (qua máy chủ), bấm vào bản đồ hoặc kéo ghim để chỉnh; vòng tròn = bán kính được chấm công.
// Sửa tay ô vĩ độ / kinh độ thì ghim chạy theo. Leaflet tải riêng khi mở hộp thoại.

const DEFAULT_CENTER: [number, number] = [21.0285, 105.8542] // Hà Nội, khi chi nhánh chưa có tọa độ
const CONNECT_ERROR = 'Không kết nối được dịch vụ tìm địa chỉ. Thử lại sau, hoặc bấm thẳng vào bản đồ / dán tọa độ.'

type Props = {
  latitude: string
  longitude: string
  radius: number
  address: string
  onPick: (latitude: string, longitude: string) => void
}

export function BranchLocationMap({ latitude, longitude, radius, address, onPick }: Props) {
  const container = useRef<HTMLDivElement>(null)
  const map = useRef<{ L: typeof import('leaflet'); map: LeafletMap; marker?: Marker; circle?: Circle } | null>(null)
  const pickRef = useRef(onPick)
  pickRef.current = onPick
  const radiusRef = useRef(radius)
  radiusRef.current = radius > 0 ? radius : 100
  const [query, setQuery] = useState(address)
  const [searching, setSearching] = useState(false)
  const [results, setResults] = useState<AddressPlace[]>([])
  const [message, setMessage] = useState<string | null>(null)
  const [mapError, setMapError] = useState<string | null>(null)

  // Đặt ghim + vòng bán kính; fit → phóng bản đồ vừa vòng tròn.
  function place(latlng: [number, number], fit: boolean) {
    const m = map.current
    if (!m) return
    if (!m.marker || !m.circle) {
      const marker = m.L.marker(latlng, { draggable: true, autoPan: true, title: 'Kéo để chỉnh vị trí chi nhánh' }).addTo(m.map)
      const circle = m.L.circle(latlng, { radius: radiusRef.current, color: '#0b57d0', weight: 2, fillOpacity: 0.12 }).addTo(m.map)
      marker.on('drag', () => circle.setLatLng(marker.getLatLng()))
      marker.on('dragend', () => pick(marker.getLatLng().lat, marker.getLatLng().lng, false))
      m.marker = marker
      m.circle = circle
    } else {
      m.marker.setLatLng(latlng)
      m.circle.setLatLng(latlng)
    }
    if (fit) m.map.fitBounds(m.circle.getBounds(), { padding: [24, 24], maxZoom: 18 })
  }

  function pick(lat: number, lng: number, fit: boolean) {
    place([lat, lng], fit)
    pickRef.current(lat.toFixed(7), lng.toFixed(7))
  }

  useEffect(() => {
    let cancelled = false
    let observer: ResizeObserver | undefined
    Promise.all([
      import('leaflet'),
      import('leaflet/dist/leaflet.css'),
      import('leaflet/dist/images/marker-icon.png'),
      import('leaflet/dist/images/marker-icon-2x.png'),
      import('leaflet/dist/images/marker-shadow.png'),
    ])
      .then(([mod, , icon, icon2x, shadow]) => {
        if (cancelled || !container.current) return
        const L = (mod as { default?: typeof import('leaflet') }).default ?? mod
        L.Icon.Default.mergeOptions({ iconUrl: icon.default, iconRetinaUrl: icon2x.default, shadowUrl: shadow.default })
        const start = toPoint(latitude, longitude)
        // Cuộn chuột chỉ phóng to sau khi bấm vào bản đồ: không "nuốt" thao tác cuộn hộp thoại.
        const m = L.map(container.current, { scrollWheelZoom: false }).setView(start ?? DEFAULT_CENTER, start ? 17 : 12)
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
          maxZoom: 19,
          attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
        }).addTo(m)
        m.attributionControl.setPrefix('<a href="https://leafletjs.com" target="_blank" rel="noopener">Leaflet</a>')
        m.once('focus', () => m.scrollWheelZoom.enable())
        m.on('click', (e) => pick(e.latlng.lat, e.latlng.lng, false))
        map.current = { L, map: m }
        if (start) place(start, true)
        // Hộp thoại đổi cỡ → báo Leaflet tính lại khung.
        observer = new ResizeObserver(() => m.invalidateSize())
        observer.observe(container.current)
      })
      .catch(() => !cancelled && setMapError('Không tải được bản đồ. Vẫn có thể nhập tọa độ bằng tay.'))
    return () => {
      cancelled = true
      observer?.disconnect()
      map.current?.map.remove()
      map.current = null
    }
    // Chỉ tạo bản đồ một lần; đổi tọa độ / bán kính xử lý ở hai effect dưới.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  // Ô vĩ độ / kinh độ đổi (gõ tay, dán, "Lấy vị trí hiện tại") → ghim chạy theo.
  useEffect(() => {
    const p = toPoint(latitude, longitude)
    const current = map.current?.marker?.getLatLng()
    if (!p || !map.current) return
    if (current && Math.abs(current.lat - p[0]) < 1e-7 && Math.abs(current.lng - p[1]) < 1e-7) return
    place(p, true)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [latitude, longitude])

  useEffect(() => {
    const m = map.current
    if (!m?.circle) return
    m.circle.setRadius(radiusRef.current)
    m.map.fitBounds(m.circle.getBounds(), { padding: [24, 24], maxZoom: 18 })
  }, [radius])

  // Tìm qua máy chủ (Nominatim / Photon, tự thử lại khi địa chỉ đủ số nhà / ngõ không khớp và trả matched).
  async function search() {
    const text = query.trim()
    if (!text || searching) return
    setSearching(true)
    setMessage(null)
    setResults([])
    try {
      const data = await api.addressSearch(text)
      setResults(data.results)
      if (data.results.length === 0) {
        setMessage('Không tìm thấy địa chỉ. Thử ghi ngắn hơn (tên đường, phường, quận, tỉnh) hoặc bấm thẳng vào bản đồ.')
      } else if (data.matched) {
        setMessage(`Không thấy chính xác địa chỉ đã nhập, đang hiện kết quả gần nhất cho "${data.matched}". Kéo ghim về đúng cổng chi nhánh.`)
      }
    } catch (e) {
      setMessage(e instanceof ApiError && e.status !== 502 ? e.message : CONNECT_ERROR)
    } finally {
      setSearching(false)
    }
  }

  function choose(r: AddressPlace) {
    setResults([])
    setQuery(r.label)
    pick(r.lat, r.lng, true)
  }

  return (
    <div className="space-y-2">
      <div className="relative">
        <div className="flex gap-2">
          <input
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter') {
                e.preventDefault()
                search()
              }
            }}
            placeholder="Tìm địa chỉ chi nhánh, vd. 25 Trần Duy Hưng, Cầu Giấy, Hà Nội"
            aria-label="Tìm địa chỉ trên bản đồ"
            autoComplete="off"
            className="h-10 min-w-0 flex-1 rounded-lg border border-outline-variant bg-surface px-3 text-sm outline-none focus:border-primary"
          />
          <Button type="button" variant="secondary" disabled={searching || !query.trim()} onClick={search}>
            {searching ? 'Đang tìm…' : 'Tìm'}
          </Button>
        </div>
        {results.length > 0 && (
          <ul
            role="listbox"
            aria-label="Kết quả tìm địa chỉ"
            className="absolute inset-x-0 top-full z-[1100] mt-1 max-h-64 overflow-y-auto rounded-lg border border-outline-variant bg-surface py-1 shadow-lg"
          >
            {results.map((r) => (
              <li key={r.id}>
                <button
                  type="button"
                  onClick={() => choose(r)}
                  className="w-full px-3 py-2 text-left text-xs hover:bg-surface-container-low"
                >
                  {r.label}
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>
      {message && <p className="text-xs text-on-surface-variant">{message}</p>}
      {mapError ? (
        <Alert tone="info">{mapError}</Alert>
      ) : (
        <div
          ref={container}
          data-testid="branch-map"
          className="relative z-0 h-64 w-full overflow-hidden rounded-lg border border-outline-variant bg-surface-container-low sm:h-72"
        />
      )}
      <p className="text-xs text-on-surface-variant">
        Bấm vào bản đồ hoặc kéo ghim đến đúng cổng chi nhánh; vòng tròn là vùng được chấm công.
      </p>
    </div>
  )
}
