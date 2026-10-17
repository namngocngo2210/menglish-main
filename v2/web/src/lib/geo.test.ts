import { describe, expect, it } from 'vitest'
import { parseCoordinates, toPoint } from './geo'

describe('geo', () => {
  it('đọc tọa độ dán từ Google Maps', () => {
    expect(parseCoordinates('21.028511, 105.854165')).toEqual(['21.028511', '105.854165'])
    expect(parseCoordinates('Vị trí: -8.5,  -115.26')).toEqual(['-8.5', '-115.26'])
    expect(parseCoordinates('Đội Cấn, Ba Đình')).toBeNull()
  })
  it('kiểm điểm hợp lệ', () => {
    expect(toPoint('21.0285', '105.8542')).toEqual([21.0285, 105.8542])
    expect(toPoint('91', '105')).toBeNull()
    expect(toPoint('', '105')).toBeNull()
  })
})
