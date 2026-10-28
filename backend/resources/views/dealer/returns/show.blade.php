@extends('layouts.dealer')

@section('title', $returnEntry->return_code)
@section('page-title', $returnEntry->return_code)

@section('content')
    @include('components.return-request-detail')
@endsection
