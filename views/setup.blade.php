@extends('app')

@section('content')

<section>
    <div class="row">
        <div class="col-lg-4">
            <div class="box box-success">
                <div class="box-header">
                    <h3 class="box-title">Setting Up PayPal API</h3>
                </div>
                <div class="box-body">
                    <div class="box no-shadow no-border">

                        <p>
                            1. Go to Paypal developer Site <a target="_blank" href="https://developer.paypal.com/developer/applications/create">here</a>
                        </p>
                        <p>
                            2. Choose name of your app and click Create App
                        </p>
                        <p>
                            4. Now you can see and manage everything include <strong>Client ID</strong> and <strong>Secret</strong> for sandbox and live account.
                        </p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="box box-success box-solid">
                <div class="box-header">
                    <h3 class="box-title">Plugin Options</h3>
                </div>
                <div class="box-body">
                    <div class="box no-shadow no-border">
                        <div class="box-body">
                            {!! Form::open() !!}
                            @include('partials.form_errors')
                            <div class="form-group">
                                {!! Form::label('mode', 'API Mode') !!}
                                {!! Form::select('mode', ['sandbox' => 'sandbox', 'live' => 'live'], $options->where('key', 'mode')->first() ? $options->where('key', 'mode')->first()->value : 'sandbox', ['class' => 'form-control']) !!}
                            </div>
                            <div class="form-group">
                                {!! Form::label('client_id', 'Client ID') !!}
                                {!! Form::text('client_id', $options->where('key', 'client_id')->first() ? $options->where('key', 'client_id')->first()->value : '', ['class' => 'form-control']) !!}
                            </div>
                            <div class="form-group">
                                {!! Form::label('client_secret', 'Secret') !!}
                                {!! Form::text('client_secret', $options->where('key', 'client_secret')->first() ? $options->where('key', 'client_secret')->first()->value : '', ['class' => 'form-control']) !!}
                            </div>
                            <div class="form-group">
                                {!! Form::label('currency', 'Currency') !!}
                                {!! Form::select('currency', ['USD' => 'USD'], $options->where('key', 'currency')->first() ? $options->where('key', 'currency')->first()->value : 'USD', ['class' => 'form-control']) !!}
                            </div>
                        </div>
                        <div class="box-footer">
                            {!! Form::submit(trans('submit'), array('class' => 'btn btn-primary pull-right')) !!}
                            {!! Form::close() !!}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

@endsection
