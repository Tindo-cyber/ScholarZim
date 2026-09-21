@extends('errors.layout')

@section('code', '503')
@section('heading', 'ScholarZim is briefly offline')
@section('message', 'We are carrying out maintenance and will be back shortly. Nothing on your account has changed.')

{{-- No "browse scholarships" here: the application that would serve it is the
     one that is down. One way out, and it is to try again. --}}
@section('actions')
    <a class="btn btn-primary" href="{{ url('/') }}">Try again</a>
@endsection
