<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryResource;
use App\Models\Delivery;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Livraison par livreur, sur le modèle « course libre » (type Glovo).
 * Voir la migration create_deliveries_table pour le cycle de vie.
 */
class DeliveryController extends Controller
{
    private const RELATIONS = ['order.items', 'order.buyer:id,name', 'shop.user:id,adresse,ville,telephone', 'livreur:id,name,telephone'];

    // POST /api/shop/orders/{order}/livreur (vendeur) : publier une course pour ses articles.
    public function request(Request $request, Order $order)
    {
        $shopId = $request->user()->shop?->id;
        if (! $shopId) {
            return response()->json(['message' => "Vous n'avez pas de boutique."], 404);
        }

        $delivery = DB::transaction(function () use ($order, $shopId) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $items = $locked->items()->where('shop_id', $shopId)->get();

            if ($items->isEmpty()) {
                abort(403, 'Cette commande ne contient aucun article de votre boutique.');
            }

            // Prêt à partir : payé par carte, ou commande à la livraison pas encore expédiée.
            $attendu = $locked->isCashOnDelivery() ? 'en_attente' : 'payee';
            if ($locked->statut === 'annulee' || $items->contains(fn ($i) => $i->statut !== $attendu)) {
                abort(409, 'Ces articles ne peuvent pas être confiés à un livreur dans leur état actuel.');
            }

            $existing = Delivery::where('order_id', $locked->id)->where('shop_id', $shopId)->first();
            if ($existing && $existing->statut !== 'annulee') {
                abort(409, 'Une course existe déjà pour ce colis.');
            }

            // Frais du colis figés à l'achat ; le livreur en touche sa part (80 % par défaut).
            $frais = (float) $locked->frais_livraison_colis;
            $data = ['statut' => 'disponible', 'livreur_id' => null, 'code' => Delivery::newCode(),
                'accepted_at' => null, 'picked_up_at' => null, 'delivered_at' => null,
                'frais' => $frais,
                'remuneration_livreur' => round($frais * Delivery::partLivreur() / 100, 2),
                'livreur_lat' => null, 'livreur_lng' => null, 'position_at' => null];

            return $existing
                ? tap($existing)->update($data)
                : Delivery::create($data + ['order_id' => $locked->id, 'shop_id' => $shopId]);
        });

        return response()->json(DeliveryResource::make($delivery->load(self::RELATIONS))->resolve(), 201);
    }

    // GET /api/livraisons/disponibles (livreur) : courses libres, les plus anciennes d'abord.
    public function available(Request $request)
    {
        $deliveries = Delivery::with(self::RELATIONS)
            ->where('statut', 'disponible')
            ->when($request->filled('ville'), fn ($q) => $q->whereHas('order',
                fn ($o) => $o->where('ville_livraison', 'like', '%'.addcslashes($request->input('ville'), '\\%_').'%')))
            ->oldest()
            ->limit(50)
            ->get();

        return DeliveryResource::collection($deliveries);
    }

    // GET /api/livraisons/mine (livreur) : courses en cours + 30 dernières terminées.
    public function mine(Request $request)
    {
        $user = $request->user();
        $actives = $user->deliveries()->with(self::RELATIONS)->whereIn('statut', ['acceptee', 'en_route'])->oldest('accepted_at')->get();
        $terminees = $user->deliveries()->with(self::RELATIONS)->where('statut', 'livree')->latest('delivered_at')->limit(30)->get();

        return DeliveryResource::collection($actives->concat($terminees));
    }

    // POST /api/livraisons/{delivery}/accepter (livreur)
    public function accept(Request $request, Delivery $delivery)
    {
        $user = $request->user();

        $enCours = $user->deliveries()->whereIn('statut', ['acceptee', 'en_route'])->count();
        if ($enCours >= Delivery::MAX_COURSES_ACTIVES) {
            return response()->json(['message' => 'Terminez vos courses en cours avant d’en accepter une autre (maximum '.Delivery::MAX_COURSES_ACTIVES.').'], 409);
        }

        // Mise à jour conditionnelle : si deux livreurs acceptent en même temps, un seul gagne.
        $pris = Delivery::whereKey($delivery->id)
            ->where('statut', 'disponible')
            ->whereNull('livreur_id')
            ->update(['statut' => 'acceptee', 'livreur_id' => $user->id, 'accepted_at' => now()]);

        if (! $pris) {
            return response()->json(['message' => 'Cette course vient d’être prise par un autre livreur.'], 409);
        }

        return response()->json(DeliveryResource::make($delivery->fresh(self::RELATIONS))->resolve());
    }

    // POST /api/livraisons/{delivery}/abandonner (livreur) : avant récupération uniquement.
    public function release(Request $request, Delivery $delivery)
    {
        $this->ensureAssigned($request, $delivery);

        if ($delivery->statut !== 'acceptee') {
            return response()->json(['message' => 'Un colis déjà récupéré doit être livré.'], 409);
        }

        $delivery->update(['statut' => 'disponible', 'livreur_id' => null, 'accepted_at' => null]);

        return response()->json(['message' => 'Course remise à disposition des autres livreurs.']);
    }

    // POST /api/livraisons/{delivery}/recuperer (livreur) : colis pris chez le vendeur.
    public function pickUp(Request $request, Delivery $delivery)
    {
        $this->ensureAssigned($request, $delivery);

        $delivery = DB::transaction(function () use ($delivery) {
            $locked = Delivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            if ($locked->statut !== 'acceptee') {
                abort(409, 'Cette course ne peut pas passer « en route ».');
            }

            $order = Order::whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
            if ($order->statut === 'annulee') {
                abort(409, 'La commande a été annulée.');
            }

            $order->advanceShopItems($locked->shop_id, 'expediee');
            $locked->update(['statut' => 'en_route', 'picked_up_at' => now()]);

            return $locked;
        });

        return response()->json(DeliveryResource::make($delivery->fresh(self::RELATIONS))->resolve());
    }

    // POST /api/livraisons/{delivery}/livrer (livreur) : remise avec le code de l'acheteur.
    public function deliver(Request $request, Delivery $delivery)
    {
        $this->ensureAssigned($request, $delivery);
        $request->validate(['code' => ['required', 'string', 'size:4']]);

        // Un mauvais code renvoie [statut, message] au lieu d'abort() : le compteur d'erreurs
        // doit être enregistré, alors qu'une exception annulerait la transaction.
        $result = DB::transaction(function () use ($delivery, $request) {
            $locked = Delivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            if ($locked->statut !== 'en_route') {
                abort(409, 'Récupérez d’abord le colis chez le vendeur.');
            }
            if ($locked->code_bloque_jusqu_a?->isFuture()) {
                return [429, 'Trop de codes incorrects. Réessayez après '.$locked->code_bloque_jusqu_a->format('H:i').'.'];
            }
            if (! hash_equals($locked->code, (string) $request->input('code'))) {
                $echecs = $locked->code_echecs + 1;
                if ($echecs >= Delivery::CODE_ECHECS_MAX) {
                    $locked->update(['code_echecs' => 0, 'code_bloque_jusqu_a' => now()->addMinutes(Delivery::CODE_BLOCAGE_MINUTES)]);

                    return [429, 'Trop de codes incorrects : saisie bloquée '.Delivery::CODE_BLOCAGE_MINUTES.' minutes. Vérifiez le code avec l’acheteur.'];
                }
                $locked->update(['code_echecs' => $echecs]);
                $restants = Delivery::CODE_ECHECS_MAX - $echecs;

                return [422, "Code de livraison incorrect. Demandez-le à l’acheteur ({$restants} essai(s) restant(s))."];
            }

            $order = Order::whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
            $order->advanceShopItems($locked->shop_id, 'livree');
            $locked->update([
                'statut' => 'livree',
                'delivered_at' => now(),
                // Paiement à la livraison : le livreur garde sa part sur les espèces encaissées,
                // elle est donc déjà réglée. Paiement carte : la plateforme la lui verse (admin).
                'remuneration_payee_at' => $order->isCashOnDelivery() ? now() : null,
                // Plus de suivi de position une fois livré.
                'livreur_lat' => null, 'livreur_lng' => null, 'position_at' => null,
                'code_echecs' => 0, 'code_bloque_jusqu_a' => null,
            ]);

            return $locked;
        });

        if (is_array($result)) {
            return response()->json(['message' => $result[1]], $result[0]);
        }

        return response()->json(DeliveryResource::make($result->fresh(self::RELATIONS))->resolve());
    }

    // POST /api/livraisons/{delivery}/position (livreur) : position GPS pendant la course.
    public function position(Request $request, Delivery $delivery)
    {
        $this->ensureAssigned($request, $delivery);
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        if (! in_array($delivery->statut, ['acceptee', 'en_route'], true)) {
            return response()->json(['message' => 'Course terminée : position non enregistrée.'], 409);
        }

        $delivery->update(['livreur_lat' => $data['lat'], 'livreur_lng' => $data['lng'], 'position_at' => now()]);

        return response()->noContent();
    }

    // POST /api/commandes/livraisons/{delivery}/confirmer (acheteur) : « J'ai bien reçu ma commande ».
    public function confirmReceipt(Request $request, Delivery $delivery)
    {
        $this->ensureBuyer($request, $delivery);

        if ($delivery->statut !== 'livree') {
            return response()->json(['message' => 'Le livreur n’a pas encore confirmé la remise du colis.'], 409);
        }
        if ($delivery->recu_confirme_at || $delivery->probleme_signale_at) {
            return response()->json(['message' => 'Vous avez déjà répondu pour ce colis.'], 409);
        }

        $delivery->update(['recu_confirme_at' => now(), 'recu_confirme_auto' => false]);

        return response()->json(DeliveryResource::make($delivery->fresh(self::RELATIONS))->resolve());
    }

    // POST /api/commandes/livraisons/{delivery}/signaler (acheteur) : problème à la réception.
    public function reportProblem(Request $request, Delivery $delivery)
    {
        $this->ensureBuyer($request, $delivery);
        $data = $request->validate(['message' => ['required', 'string', 'min:5', 'max:1000']]);

        if ($delivery->statut !== 'livree') {
            return response()->json(['message' => 'Le colis n’a pas encore été livré.'], 409);
        }
        if ($delivery->recu_confirme_at || $delivery->probleme_signale_at) {
            return response()->json(['message' => 'Vous avez déjà répondu pour ce colis.'], 409);
        }
        if ($delivery->delivered_at && $delivery->delivered_at->lt(now()->subHours(Delivery::delaiConfirmationHeures()))) {
            return response()->json(['message' => 'Le délai pour signaler un problème est dépassé. Contactez le support.'], 409);
        }

        // La rémunération du livreur reste bloquée tant que l'admin n'a pas traité le litige.
        $delivery->update(['probleme_signale' => $data['message'], 'probleme_signale_at' => now()]);

        return response()->json(DeliveryResource::make($delivery->fresh(self::RELATIONS))->resolve());
    }

    // GET /api/livraisons/gains (livreur) : résumé de la rémunération.
    public function earnings(Request $request)
    {
        $livrees = $request->user()->deliveries()->where('statut', 'livree');

        return response()->json([
            'courses_livrees' => (clone $livrees)->count(),
            'gains_total' => round((float) (clone $livrees)->sum('remuneration_livreur'), 2),
            'gains_aujourdhui' => round((float) (clone $livrees)->whereDate('delivered_at', today())->sum('remuneration_livreur'), 2),
            // Courses payées par carte, réception confirmée : la plateforme doit verser ces gains.
            'a_recevoir' => round((float) Delivery::payables()->where('livreur_id', $request->user()->id)
                ->whereNull('remuneration_payee_at')->sum('remuneration_livreur'), 2),
            // En attente de la confirmation du client (ou litige en cours).
            'en_attente_confirmation' => round((float) (clone $livrees)->whereNull('remuneration_payee_at')
                ->where(fn ($q) => $q->whereNull('recu_confirme_at')->orWhereNotNull('probleme_signale_at'))
                ->sum('remuneration_livreur'), 2),
            'part_livreur' => Delivery::partLivreur(),
        ]);
    }

    private function ensureBuyer(Request $request, Delivery $delivery): void
    {
        if ((int) $delivery->order?->buyer_id !== (int) $request->user()->id) {
            abort(403, 'Ce colis ne vous est pas destiné.');
        }
    }

    private function ensureAssigned(Request $request, Delivery $delivery): void
    {
        if ((int) $delivery->livreur_id !== (int) $request->user()->id) {
            abort(403, 'Cette course ne vous est pas attribuée.');
        }
    }
}
