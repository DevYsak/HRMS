@extends('errors.app')

@section('code', '503')
@section('title', __('Pulse is being updated'))
@section('message', __('We\'re applying an update and will be back in a few minutes.'))
@section('primary')
    <a class="btn btn-primary" href="javascript:location.reload()">Try again</a>
@endsection
