@extends('errors.app')

@section('code', '403')
@section('title', __("You don't have access"))
@php
    // The app's own refusals are written for people ("Your account is not
    // linked to an employee record."); framework defaults are replaced.
    $reason = trim((string) ($exception?->getMessage() ?? ''));
    $generic = in_array($reason, ['', 'Forbidden', 'This action is unauthorized.', 'Unauthorized.'], true);
    $message = $generic
        ? "You don't have permission to open this page. If you need it, ask HR or your administrator."
        : \Illuminate\Support\Str::limit($reason, 200);
@endphp
@section('message', $message)
