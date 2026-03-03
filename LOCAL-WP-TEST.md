# Lokal testen (WordPress + Docker)

1. Starten:
   - `docker compose up -d --build`
2. Im Browser oeffnen:
   - `http://localhost:8080`
3. WordPress kurz einrichten und einloggen.
4. Plugin aktivieren:
   - `La Palma Menu Importer`
5. Testen:
   - Im Admin-Menue `La Palma Menu` die PDF hochladen.
   - Kontrollieren, ob Woerter wie `Rinderfilet` und `Schwertfisch` korrekt angezeigt werden.

## Nuetzliche Befehle

- Logs ansehen: `docker compose logs -f wordpress`
- Stoppen: `docker compose down`
- Komplett resetten (inkl. DB): `docker compose down -v`
