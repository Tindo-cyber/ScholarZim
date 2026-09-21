@extends('errors.layout')

@section('code', '419')
@section('heading', 'Your session expired')
@section('message', 'For your security, the page sat open too long and the form could no longer be submitted. Sign in again and repeat what you were doing - nothing was saved.')

@section('actions')
    <a class="btn btn-primary" href="{{ route('login') }}">Sign in again</a>
    <a class="btn btn-outline-secondary" href="{{ url('/') }}">Back to home</a>
@endsection
