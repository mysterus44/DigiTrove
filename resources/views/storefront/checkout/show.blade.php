{{-- Checkout invité : e-mail seulement. Aucun prix, devise, total ou remise n'est
     soumis par le client — ces valeurs appartiennent aux autorités P3-D. --}}
@extends('storefront.layouts.app')

@section('title', 'Finaliser la commande - DigiTrove')
@section('meta_description', 'Finaliser votre commande DigiTrove.')

@section('content')
    <section class="catalog-header">
        <p class="eyebrow">Commande</p>
        <h1>Finaliser la commande</h1>
    </section>

    <section class="checkout-summary">
        <h2>Votre commande</h2>
        <ul>
            @foreach ($lines as $line)
                <li>
                    @if ($line['available'])
                        {{ $line['product']->name }}
                        <strong>{{ number_format($line['priceMinor'], 0, ',', ' ') }} {{ $currency }}</strong>
                    @else
                        <span>Article indisponible — retirez-le de votre panier pour continuer</span>
                    @endif
                </li>
            @endforeach
        </ul>
        <p><strong>Total : {{ number_format($totalMinor, 0, ',', ' ') }} {{ $currency }}</strong></p>
        <p><a href="{{ route('cart.show') }}">Modifier mon panier</a></p>
    </section>

    <section class="checkout-form">
        <form method="POST" action="{{ route('checkout.store') }}">
            @csrf
            <label for="checkout-email">
                Adresse e-mail
                <span>Vos liens de téléchargement y seront envoyés. Aucun compte n'est créé.</span>
            </label>
            <input id="checkout-email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email" />

            @error('email')
                <p class="form-error" role="alert">{{ $message }}</p>
            @enderror

            <button type="submit">Payer {{ number_format($totalMinor, 0, ',', ' ') }} {{ $currency }}</button>
        </form>
    </section>
@endsection
