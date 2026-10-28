@extends('layouts.customer')

@section('title', $returnEntry->return_code)

@section('content')
    @include('components.return-request-detail')
@endsection
