@extends('errors.app')

@section('title', __('Forbidden'))
@section('icon', 'lock-closed')
@section('code', '403')
@section('message', __($exception->getMessage() ?: 'You don\'t have permission to access this page. If you think this is a mistake, contact your administrator.'))
