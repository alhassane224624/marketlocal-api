# Déploiement gratuit de MarketLocal

| Partie | Service | URL obtenue (exemple) |
|---|---|---|
| Base MySQL | Aiven (Free) | `mysql-xxxx.aivencloud.com:12345` |
| Images | Cloudinary (Free) | — |
| API Laravel | Render (Free, Docker) | `https://marketlocal-api.onrender.com` |
| Frontend Next.js | Vercel (Hobby) | `https://marketlocal.vercel.app` |

Ordre à suivre : **1 → 2 → 3 → 4 → 5**.

---

## 1. Base de données — Aiven

1. Créer un compte sur <https://aiven.io> → **Create service** → **MySQL** → plan **Free**.
2. Une fois le service « Running », noter dans l'onglet **Overview** :
   `Host`, `Port`, `User` (avnadmin), `Password`, `Database name` (defaultdb).
3. Télécharger le **CA certificate** (bouton *Download* à côté de « CA certificate »).
   Ouvrir le fichier `ca.pem` avec le Bloc-notes : on collera tout son contenu dans Render.

## 2. Images — Cloudinary

1. Créer un compte sur <https://cloudinary.com>.
2. Dans **Dashboard / API Keys**, noter : `Cloud name`, `API Key`, `API Secret`.

## 3. API Laravel — Render

Pré-requis : le dépôt GitHub du backend contient `Dockerfile`, `.dockerignore`, `docker/entrypoint.sh` et `render.yaml`.

1. Générer une clé d'application en local :
   ```bash
   php artisan key:generate --show
   ```
   Copier la valeur (`base64:...`).
2. Sur <https://render.com> → **New** → **Blueprint** → choisir le dépôt du backend.
   Render lit `render.yaml` et demande les variables marquées « secret » :

   | Variable | Valeur |
   |---|---|
   | `APP_KEY` | la valeur `base64:...` de l'étape 1 |
   | `APP_URL` | `https://marketlocal-api.onrender.com` (l'URL Render, à corriger après la création si besoin) |
   | `FRONTEND_URLS` | `https://marketlocal.vercel.app` (à compléter à l'étape 4) |
   | `DB_HOST` / `DB_PORT` / `DB_PASSWORD` | valeurs Aiven |
   | `MYSQL_SSL_CA_PEM` | tout le contenu de `ca.pem` (de `-----BEGIN` à `END CERTIFICATE-----`) |
   | `CLOUDINARY_CLOUD_NAME` / `_API_KEY` / `_API_SECRET` | valeurs Cloudinary |
   | `STRIPE_SECRET` | `sk_test_...` |
   | `STRIPE_WEBHOOK_SECRET` | laisser vide pour l'instant (étape 5) |

3. Lancer le déploiement. Au démarrage, le conteneur :
   - exécute les migrations (`migrate --force`) ;
   - insère les données de démo **uniquement si la base est vide** ;
   - lance le planificateur (annulation des commandes expirées).
4. Tester : `https://marketlocal-api.onrender.com/up` doit afficher une page verte,
   et `/api/products` doit renvoyer les produits de démo.

> Plan gratuit : le service s'endort après ~15 min sans visite. Le premier appel suivant prend 30 à 50 s.
> Le planificateur ne tourne que lorsque le service est réveillé.

## 4. Frontend Next.js — Vercel

1. Sur <https://vercel.com> → **Add New** → **Project** → importer `marketlocal-web`.
2. Dans **Environment Variables** :

   | Variable | Valeur |
   |---|---|
   | `NEXT_PUBLIC_API_URL` | `https://marketlocal-api.onrender.com/api` |
   | `NEXT_PUBLIC_STRIPE_PUBLISHABLE_KEY` | `pk_test_...` |

3. **Deploy**. Noter l'URL finale (ex. `https://marketlocal.vercel.app`).
4. Retour sur Render → **Environment** → mettre cette URL dans `FRONTEND_URLS`
   (plusieurs URL possibles, séparées par des virgules). Render redéploie tout seul.

## 5. Webhook Stripe (mode test)

1. Tableau de bord Stripe (mode test) → **Developers → Webhooks → Add endpoint**.
2. URL : `https://marketlocal-api.onrender.com/api/stripe/webhook`
3. Événements : `payment_intent.succeeded` et `account.updated`.
4. Copier le **Signing secret** (`whsec_...`) dans `STRIPE_WEBHOOK_SECRET` sur Render.

Stripe CLI n'est plus nécessaire en production.

---

## Comptes de démo (créés par les seeders)

| Rôle | E-mail | Mot de passe |
|---|---|---|
| Admin | admin@marketlocal.test | password |
| Vendeur | vendeur@marketlocal.test | password |
| Acheteur | acheteur@marketlocal.test | password |

## En cas de problème

- **Erreur CORS dans le navigateur** : `FRONTEND_URLS` ne correspond pas exactement à l'URL Vercel (pas de `/` final).
- **`SQLSTATE[HY000] [2002]` ou erreur SSL** : vérifier `DB_HOST`, `DB_PORT` et le contenu complet de `MYSQL_SSL_CA_PEM`.
- **Logs** : Render → service → onglet **Logs**.
