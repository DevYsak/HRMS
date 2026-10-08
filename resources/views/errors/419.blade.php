@extends('errors.app')

@section('code', '419')
@section('title', __('Your session expired'))
@section('message', __('For your security you were signed out after a period of inactivity. Sign in again to carry on — nothing was saved from the last action.'))
@section('primary')
    <a class="btn btn-primary" href="{{ rescue(fn () => route('login'), url('/'), report: false) }}">Sign in again</a>
@endsection
