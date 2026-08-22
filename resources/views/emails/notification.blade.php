@extends('emails.layout', ['title' => 'ScholarZim notification', 'actionLabel' => 'View in ScholarZim'])

@section('body')
    <p style="margin:0 0 12px;">Hi {{ $user->full_name ?: 'there' }},</p>
    <p style="margin:0;">{{ $message }}</p>
@endsection
