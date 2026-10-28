@extends('layouts.customer')

@section('title', 'Yêu cầu trả hàng · '.$order->order_code)

@section('content')
    @include('components.return-request-form')
@endsection
