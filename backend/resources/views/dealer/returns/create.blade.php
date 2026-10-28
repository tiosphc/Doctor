@extends('layouts.dealer')

@section('title', 'Yêu cầu trả hàng · '.$order->order_code)
@section('page-title', 'Yêu cầu trả hàng')

@section('content')
    @include('components.return-request-form')
@endsection
