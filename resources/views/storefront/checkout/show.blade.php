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

        <ul class="checkout-lines">
            @foreach ($lines as $line)
                <li class="checkout-line">
                    @if ($line['available'])
                        <span class="checkout-line-name">{{ $line['product']->name }}</span>
                        <span class="checkout-line-price">{{ number_format($line['priceMinor'], 0, ',', ' ') }} {{ $currency }}</span>
                    @else
                        <span class="cart-line-unavailable">Article indisponible — retirez-le de votre panier pour continuer</span>
                    @endif
                </li>
            @endforeach
        </ul>

        <p class="checkout-total">
            <span>Total</span>
            <strong>{{ number_format($totalMinor, 0, ',', ' ') }} {{ $currency }}</strong>
        </p>

        <div class="checkout-actions">
            <a class="button button-secondary" href="{{ route('cart.show') }}">Modifier mon panier</a>
        </div>
    </section>

    <section class="checkout-form">
        <form method="POST" action="{{ route('checkout.store') }}">
            @csrf

            {{-- Le texte d'aide est SORTI du label : à l'intérieur, il devenait partie du
                 nom accessible, et un lecteur d'écran annonçait le champ comme
                 « Adresse e-mail Vos liens de téléchargement y seront envoyés... ».
                 Relié par aria-describedby, il reste lu, mais comme une description. --}}
            <label for="checkout-email">Adresse e-mail</label>
            <span class="checkout-form-help" id="checkout-email-help">Vos liens de téléchargement y seront envoyés. Aucun compte n'est créé.</span>

            <input id="checkout-email" type="email" name="email" value="{{ old('email') }}"
                   required autocomplete="email" aria-describedby="checkout-email-help"
                   @error('email') aria-invalid="true" @enderror />

            @error('email')
                <p class="form-error" role="alert">{{ $message }}</p>
            @enderror

            <p class="checkout-actions">
                <button class="button button-primary" type="submit">Payer {{ number_format($totalMinor, 0, ',', ' ') }} {{ $currency }}</button>
            </p>
        </form>
    </section>
@endsection
