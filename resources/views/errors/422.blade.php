@extends('errors.layout')
@section('code', '422')
@section('title', 'Không thể thực hiện thao tác')
@section('message', preg_match('/[^\x00-\x7F]/', (string) $exception->getMessage()) ? $exception->getMessage() : 'Dữ liệu hiện tại không cho phép thao tác này.')
