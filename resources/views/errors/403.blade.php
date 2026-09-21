@extends('errors.layout')

@section('code', '403')
@section('heading', 'You do not have access to this')
@section('message', 'This area belongs to a different kind of account. If you think that is wrong, ask an administrator to check your account.')

@section('actions')
    <a class="btn btn-primary" href="{{ url('/') }}">Back to home</a>
    <a class="btn btn-outline-secondary" href="{{ route('login') }}">Sign in as someone else</a>
@endsection
