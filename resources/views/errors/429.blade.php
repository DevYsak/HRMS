@extends('errors.app')

@section('code', '429')
@section('title', __('Too many requests'))
@section('message', __('You\'ve done that too many times in a short while. Wait a minute and try again.'))
