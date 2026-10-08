@extends('errors.app')

@section('code', '500')
@section('title', __('Something went wrong'))
@section('message', __('An unexpected error stopped this page. It has been logged and the team can trace it with the reference below.'))
@section('show_reference', 'yes')
