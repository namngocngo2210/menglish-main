import { describe, expect, it } from 'vitest'
import { distanceMeters, fitSize, parseCoordinates, toPoint } from './geo'

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
  it('khoảng cách như máy chủ', () => {
    expect(Math.round(distanceMeters(21.0285, 105.8542, 21.0465, 105.8542))).toBe(2002)
    expect(distanceMeters(21.0285, 105.8542, 21.0285, 105.8542)).toBe(0)
  })
  it('thu nhỏ ảnh theo cạnh dài', () => {
    expect(fitSize(1920, 1080, 720)).toEqual([720, 405])
    expect(fitSize(400, 300, 720)).toEqual([400, 300])
  })
})
