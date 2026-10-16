# Istruzioni per agenti AI: connettore di I tuoi professionisti digitali

Regole per chi scrive codice che usa `ituoiprofessionistidigitali/connector` in un sistema collegato al portale. Il contratto completo è `docs/contratto.md` nel repo del portale: il portale ne è l'autorità e il sistema si adegua, mai il contrario.

## Non aggirare il pacchetto

- Parla con il portale solo con `Platform::…` (o `PlatformClient`). Mai una `Http::post()` a mano verso `/api/v1`: token, nuovi tentativi, validazione ed errori stanno nel client.
- Costruisci gli eventi con `EnvelopeData::make()` o, per rispondere, con `$evento->reply()`. Non comporre la busta come array.
- Non inventare tipi di evento, versioni o campi del payload: esistono solo quelli di `Platform::eventTypes()`. Un tipo nuovo nasce nel portale, con una pull request, prima che un sistema lo mandi.
- Non usare classi o metodi marcati `@internal`: li usa solo il pacchetto e cambiano senza avviso. Delle outbox usa `ProfessionalOutbox::declare()`, `TransactionOutbox::payloadOf()` e `isRecorded()`.

## Tipologie

- Non dare mai per scontato `commercialisti`. Le tipologie sono un catalogo (`Platform::typologies()`): un codice è un dato, non una costante sparsa nel codice.
- Un aderente può avere più tipologie. Ogni evento dichiara la tipologia dello scambio, che dev'essere comune a mittente e destinatario.

## Aderenti

- `Platform::syncMembers()` vuole l'elenco **completo** degli aderenti del sistema: chi manca diventa inattivo. Non mandare mai un elenco parziale, né «solo quello cambiato».
- `external_ref` è il tuo riferimento stabile e opaco: non cambiarlo e non riusarlo per un altro soggetto.
- Salva l'`id` che il portale restituisce per ogni aderente: è il `sender` dei tuoi eventi. Gli id degli altri sistemi arrivano da `Platform::searchMembers()` o dagli eventi ricevuti.
- `listed: false` tiene l'aderente fuori dalla ricerca, ma non dagli scambi.
- Partita IVA e codice fiscale vanno in maiuscolo, senza spazi: il pacchetto e il portale verificano anche il carattere di controllo.
- Ogni aderente ha `email`, obbligatoria: è l'email coworking della struttura, l'indirizzo per le notifiche della sua area (M14). È unica fra gli aderenti di tutto il portale (M15): una già usata da un aderente di un altro sistema è un 422 su `members.N.email`, da risolvere con lo studio, non da ritentare. Le email non distinguono maiuscole e minuscole (T6): `MemberData` le porta in minuscolo, come il portale le salva e le restituisce, quindi `Segreteria@x` e `segreteria@x` sono la stessa email anche per M15. Per confrontarle nel sistema, usa il minuscolo. Sul portale è anche l'email con cui lo studio entra nella sua area. Nelle chiamate del pacchetto, invece, per riconoscere un aderente usa `external_ref` o il suo `id`, mai l'email. Gli aderenti degli altri sistemi non la mostrano mai.
- I rifiuti dell'email in `syncMembers()` hanno un'eccezione propria: non leggere le chiavi di `errors` a mano. `ConcurrentMemberSyncException` è una richiesta contemporanea con la stessa email. `MemberEmailsRejectedException` porta in `->messages` i messaggi del portale per `external_ref`: si risolve con lo studio, non si ritenta.

## Accesso all'area dello studio

- Per portare un utente nell'area di un aderente chiedi un link con `Platform::memberAccessLink($idAderente)` e fai subito il redirect del browser a `$link->url`. Non scrivere un'altra chiamata a `/members/{id}/access-links`.
- L'accesso è per studio, non per persona: il portale non sa chi ha cliccato. Se serve saperlo, registralo nel sistema.
- Il link è un segreto e vale una volta sola: mai in cache, in un log (compresi Telescope e i listener delle risposte HTTP), in una vista o in una coda, e mai salvato per riusarlo. Chiedine uno nuovo a ogni accesso. Nei log, se serve, solo `expires_at`.
- `MemberNotAccessibleException` è un 404: l'aderente non è del sistema, non esiste o è inattivo. Mostra un errore all'utente e non ritentare.

## Mandare eventi

- Il `sender` è sempre un aderente **tuo**: il portale ricava il sistema dalle credenziali e rifiuta un mittente altrui.
- Salva la busta prima di mandarla. Se l'invio va in timeout, rimanda **la stessa busta**, con lo stesso `event_id`: il portale risponde 200 senza duplicare. Una busta nuova con un `event_id` nuovo è un evento nuovo.
- Un 409 vuol dire che hai riusato un `event_id` con un contenuto diverso: è un errore nel codice, non qualcosa da ritentare.
- Manda gli eventi fra studi con `resolve(EventOutbox::class)->queue($busta)`, nella stessa transazione del database del cambio che li produce, non con `Platform::send()` a mano: l'outbox salva la busta, la manda dopo il commit e la ritenta identica. Una riga `failed` di `platform_event_outbox` è un errore nel codice: leggi `last_error`.
- I file di un evento passano dall'outbox: `OutgoingFileData::make($disco, $percorso, $nome, $tipo)`, cita il suo `ref` nel payload e passa i file a `EventOutbox::queue($busta, $file)`. Non chiamare `Platform::uploadFile()` a mano per un evento in outbox, e non cambiare un file dopo `queue()`: la riga diventa `failed`.
- Nei test di ogni action che manda un evento verifica la busta con `PlatformOutbox::assertEventQueued($tipo, $callback)`, e con `assertEventNotQueued($tipo)` che non parta.

## Ricevere eventi

- La rotta del webhook la registra il pacchetto (`PLATFORM_WEBHOOK_PATH`, nome `platform.webhook`) e verifica la firma da sola. Non scriverne un'altra e non togliere il middleware della firma.
- La verifica del webhook (il `platform.ping` senza mittente) e la risposta ai `platform.ping` degli aderenti le gestisce il pacchetto. Non rispondere a mano.
- Ascolta `PlatformEventReceived`. Il listener:
  - va **in coda** (`ShouldQueue`): il portale aspetta al massimo dieci secondi;
  - è **idempotente su `event_id`**: lo stesso evento può arrivare due volte, quindi salva gli id elaborati con un indice unico;
  - non conta sull'ordine: usa `occurred_at` e `correlation_id`;
  - ignora i tipi che non gestisce, senza eccezioni.
- Un'eccezione in un listener sincrono fa rispondere 500 e il portale riprova: va bene solo se il lavoro non è stato salvato.
- Un evento del portale di un tipo, o di una versione, che il pacchetto non conosce ha 202 e un `warning` nel log, e non arriva ai listener: è il segno che il pacchetto va aggiornato. Non intercettarlo a mano.
- Gli eventi del portale (`transaction.*`) arrivano con `sender` a `null`: riconoscili con `$envelope->isFromPlatform()` e leggi il payload con il suo DTO. Non mandarli mai con `Platform::send()`: li manda solo il portale (L6).

## Candidature e scelta della controparte

- Le candidature arrivano solo agli incarichi pubblicati: dai professionisti registrati sul portale (`audience` `person` o `any`) e dagli studi aderenti di un altro sistema (`member` o `any`, L10). Leggile con `Platform::applications($reference)` o dagli eventi `transaction.application_received` (`ApplicationReceivedData`) e `transaction.application_withdrawn` (`ApplicationWithdrawnData`).
- Prima della scelta del candidato hai solo nome, cognome, comune, provincia e `tax_code_verified` di una persona, o `name`, comune e provincia di uno studio (`ApplicantData::isMember()`) (L7). Non chiedere al portale altro e non cercarlo altrove: codice fiscale, email e partita IVA arrivano con la scelta, il cellulare solo nell'evento della scelta.
- Scegli con `Platform::selectApplication($reference, $idCandidatura)`. Le eccezioni `TransactionNotFoundException`, `ApplicationNotSelectableException` e `TransactionNotPublishedException` non si ritentano: mostra l'errore e rileggi le candidature o la transazione.
- Scelto uno studio, la controparte è il suo `member_id`, con i campi della persona `null`, e `contact` è `null`: non cercare email o cellulare, i dati per i contratti arrivano con `coworking.contract_details`.
- Crea quello che serve alla scelta (l'anagrafica della persona, il cellulare) **solo** nel listener di `transaction.counterparty_selected` (`CounterpartySelectedData`), che arriva sempre, anche dopo una scelta fatta con l'API. Il listener è idempotente sull'`event_id` e sulla `reference` della transazione.
- Un `tax_code_verified: false` vuol dire codice fiscale dichiarato dal professionista, con il solo controllo formale: se lo colleghi a un'anagrafica del sistema per codice fiscale e l'email non coincide, chiedi conferma allo studio.
- Dopo la scelta il modello dell'invio passa ad `accepted` con la controparte **identica** a `$scelta->transaction->counterparty`, il `responded_at` della scelta e `signed_at` a `null` fino alla firma. `audience` ed `expires_at` restano quelli della pubblicazione. Il cellulare non va nella controparte.
- L'outbox si allinea da sola alla revisione del portale, dalla risposta della scelta e dall'evento, prima dei listener: non toccare la revisione a mano e non chiamare `Platform::recordTransaction()`.
- Uno studio del sistema si candida a un incarico di un altro sistema con `Platform::apply($idInserzione, $idAderente)`, dopo aver raccolto il consenso esplicito di chi decide per lo studio: `apply()` manda `terms_accepted: true`. Leggi le inserzioni con `Platform::listings()` e `Platform::listing()`, ritira con `Platform::withdrawApplication()`, segui l'esito con `Platform::memberApplications()`. `ListingNotFoundException`, `ApplicationRefusedException`, `ApplicationNotFoundException` e `ApplicationNotWithdrawableException` non si ritentano.
- Un incarico `published` il sistema lo porta solo a `withdrawn`: ad `accepted` lo porta il portale con la scelta (R8).
- Un incarico `published` rimasto senza scelta lo ritira anche il portale, alcuni giorni dopo `expires_at` (L9): non contare su un numero fisso di giorni, lo configura l'operatore del portale. Arriva `transaction.withdrawn` (`TransactionWithdrawnData`, `reason` `WithdrawalReason::Expired`), solo per il ritiro del portale. Il listener è in coda e idempotente sull'`event_id` e sulla `reference`: se l'invio è ancora pubblicato lo ritira come un ritiro a mano, con il `closed_at` dell'evento, altrimenti non fa nulla. Non rispondere con un `PUT`: l'outbox si allinea da sola alla revisione del portale, prima dei listener, come per la scelta.
- Una riga dell'outbox `failed` per un 409 su un ritiro del sistema, mentre il portale aveva già ritirato, è l'unico `failed` che non è un errore nel codice: la sistema l'evento `transaction.withdrawn`.

## Eventi fra studi

- Il sistema dello studio scelto riceve `transaction.assignment_received` (`AssignmentReceivedData`), con il committente rivelato (`PrincipalData`): crea lì quello che serve allo studio, idempotente sull'`event_id` e sulla transazione. Non registrare quella transazione: è del committente.
- `coworking.contract_details`, `coworking.activities_assigned` e `coworking.activity_status` si costruiscono con il loro DTO e `->toEnvelope($mittente, $destinatario, $tipologia)`, che mette `correlation_id` all'id della transazione e controlla lo schema; poi `EventOutbox::queue()`. Mai la busta a mano.
- `activities_assigned` lo manda solo il committente, dopo la firma; `activity_status` solo lo studio scelto; `contract_details` tutti e due (E13). Il committente riporta lo stato delle attività nei suoi `PUT`: lo studio scelto non registra nulla.
- Il cliente finale (`FinalClientData`) va solo in `activities_assigned`, dopo la firma dei documenti del coworking: mai prima, mai in un altro evento.

## Collegamento

- Senza `PLATFORM_URL`, `PLATFORM_CLIENT_ID` e `PLATFORM_CLIENT_SECRET` il sistema non è collegato: il client lancia `PlatformNotConfiguredException`. Controlla prima con `resolve(ConnectorConfig::class)->isConnected()` e spegni la funzione, invece di romperla.
- Dopo il deploy o quando cambia l'URL del webhook chiama `Platform::present(route('platform.webhook'))`. Finché la verifica non riesce, il sistema legge i cataloghi ma non pubblica aderenti né eventi (403 con `reason: pending`).

## Registro delle transazioni

- Ogni incarico inviato va registrato sul portale **quando nasce**, e poi a ogni cambio: nello stato `invited` se è affidato a una controparte che il sistema conosce (collaboratore persona, altra struttura dello stesso sistema), nello stato `published`, senza controparte e con `expires_at`, se è pubblicato sul portale. Non è facoltativo: è la regola R9 del contratto. Ogni PUT è la fotografia completa della transazione, quindi il portale accetta la prima registrazione in qualunque stato, purché coerente con le sue date e con le sue attività: se l'invio di `invited` non è ancora riuscito quando il collaboratore accetta, l'outbox manda direttamente `accepted`, ed è corretto.
- Una transazione è un invio con prezzi fermi: dopo la prima registrazione cambiano solo lo stato, le sue date e, per ogni attività, stato, minuti lavorati e chiusura (R13). Per cambiare un prezzo revoca l'attività e mandala in un invio nuovo, con un riferimento nuovo.
- Lo stato segue le attività (R14): `invited`, `declined`, `published` e `withdrawn` le hanno tutte `open`; `accepted` almeno una `open`; `completed` nessuna `open` e almeno una `completed`; `revoked` nessuna `open` e nessuna `completed`. Un invio ritirato prima della risposta è `revoked`, con tutte le attività `revoked`.
- `signed_at` è la data della firma dell'incarico, registrata dal sistema (R8, R22): `null` in `published`, `withdrawn`, `invited` e `declined`, facoltativa in `accepted` (`null` = in attesa di firma) e in `revoked`, obbligatoria in `completed`, fra `responded_at` e `closed_at`. Nessuno lavora prima della firma: senza firma nessuna attività `completed` e minuti lavorati `null` o `0`; un'attività `completed` non chiude prima della firma. Una firma mandata non torna `null`.
- I minuti previsti sono obbligatori su ogni attività, anche a ore: il totale c'è sempre. I minuti lavorati si mandano solo su un'attività chiusa con una controparte persona: con un aderente o senza controparte sono sempre `null`.
- Costruisci la transazione con i DTO tipizzati (`TransactionData`, `CounterpartyData`, `TransactionActivityData`, `CompensationData`, `ActivityDescriptionData`, `ProcessData`), mai con un array a mano. La mappatura dai modelli del sistema sta in una sola classe del sistema: il pacchetto non conosce i tuoi modelli.
- Una controparte persona porta l'anagrafica del professionista: codice fiscale, nome, cognome ed email obbligatori; partita IVA, comune e provincia se il sistema li ha, altrimenti `null`. Senza email `CounterpartyData::person()` non si costruisce e `toPlatformTransaction()` lancia un errore al salvataggio dell'invio: chiedi l'email del collaboratore prima di affidargli un incarico. Il DTO porta l'email in minuscolo (T6). È la fotografia dell'invio: identica a ogni revisione, anche se poi l'anagrafica cambia (R13, un cambio è un 422). Un aderente porta il suo `member_id`, con i campi della persona a `null`: la controparte ha sempre le nove chiavi.
- `audience` è sempre valorizzato: in un incarico affidato è il tipo della controparte (`person` o `member`, mai `any`), in uno pubblicato a chi è aperto (`person`, `member` o `any`). Non cambia dopo la prima registrazione.
- Un incarico pubblicato ha `counterparty` a `null` ed `expires_at`; uno affidato ha `expires_at` a `null`. `title` e `description` sono obbligatori in ogni stato.
- Tutte le chiavi ci sono sempre, anche a `null`: testata, controparte, attività, compenso e descrizione. Costruisci con i DTO, che le emettono tutte.
- La parte ferma all'invio (audience, controparte, tipologia, testi, prezzi) la costruisci da una fotografia salvata all'invio, non dai dati di oggi.
- Il modello dell'invio implementa `RecordsPlatformTransaction` e usa il trait `RecordsOnPlatform`; `platformTransactionReference()` è `null` per una bozza, che non si registra. I modelli che cambiano l'invio senza esserlo (le righe, l'incarico che lo contiene) implementano `AffectsPlatformTransactions` e usano `RecordsAffectedOnPlatform`. Il pacchetto scrive ogni salvataggio in una outbox, nella stessa transazione del database, e lo manda in coda con la revisione giusta. Non chiamare `Platform::recordTransaction()` a mano per questi modelli.
- La registrazione segue il lavoro, non lo blocca: l'affidamento è operativo da subito e l'outbox lo comunica dopo. `$model->isRecordedOnPlatform()` serve a mostrare se il portale ha confermato l'ultima versione, non a fermare il lavoro.
- Una transazione non si cancella mai sul portale (R12): prima di eliminare un affidamento portalo a `revoked`, con `closed_at`, e salvalo; poi eliminalo. L'outbox manda la versione salvata anche dopo il delete.
- Mai aggiornamenti di massa (`Model::query()->update()`, `DB::table()->update()`) sui campi che finiscono in `toPlatformTransaction()`: saltano il modello e l'outbox. Per quei campi aggiorna riga per riga. Sui campi che non fanno parte della transazione, come l'ultimo accesso del collaboratore, l'aggiornamento di massa va bene.
- Il comando `platform:send-outbox` gira da solo ogni cinque minuti e rimanda le versioni rimaste indietro: serve che lo scheduler di Laravel sia attivo.
- Ogni attività porta il suo processo, `ProcessData`, obbligatorio: `reference` è un identificativo opaco del sistema, mai un nome, stabile per ogni istanza del soggetto (processo di linea o di staff, progetto, agenda) e uguale per tutte le sue attività; `name` è il nome del catalogo, o `null`.
- La descrizione `assignment` di ogni attività porta solo i nomi del modello di processo e dell'attività del catalogo dello studio, oppure `null`: **mai** il nome, il codice fiscale o la partita IVA del cliente, il nome di un'agenda o di un'area di progetto, né un testo libero.
- R6: niente testo libero nelle descrizioni delle attività; `title` e `description` della testata sono testi dello studio, senza dati del cliente; un incarico pubblicato li mostra sul portale. In un incarico pubblicato sono obbligatori: `title` testo semplice fino a 255 caratteri, `description` Markdown fino a 10000.
- Lo storico già esistente si carica una volta con `Platform::recordTransactions()`, fino a 500 per chiamata. Segue le stesse regole della PUT (R10), senza eccezioni. Le transazioni registrate entrano nella outbox come confermate: non caricarle in un altro modo, o le loro persone restano fuori dalle dichiarazioni dell'anagrafica.
- Una riga dell'outbox in stato `failed` è un errore nel codice (contratto violato o conflitto di revisione): leggi `last_error` e correggi, non ritentare alla cieca.
- Un test di architettura nel progetto rende l'obbligo verificabile:

```php
arch('assignments reach the register of the platform')
    ->expect(App\Models\P2pExternalAssignmentProposal::class)
    ->toImplement(ITuoiProfessionistiDigitali\Connector\Contracts\RecordsPlatformTransaction::class)
    ->toUseTrait(ITuoiProfessionistiDigitali\Connector\Concerns\RecordsOnPlatform::class);
```

- Nei test di ogni action che tocca un invio, le sue righe o l'incarico, verifica la comunicazione con `ITuoiProfessionistiDigitali\Connector\Testing\PlatformOutbox::assertRecorded($proposta)`, e con `assertNotRecorded()` che una bozza non parta.

## Anagrafica dei professionisti

- `declare()` non manda nulla per una persona che la outbox delle transazioni non ha mai visto, e non ne controlla nemmeno i dati: chiamala a ogni cambio, senza filtri tuoi, anche per un referente senza email (passa `email: ''`). Per una persona incaricata, invece, dati non validi sono una `ValidationException`.
- Quando l'anagrafica di un collaboratore persona cambia nel sistema, dichiarala con `resolve(ProfessionalOutbox::class)->declare($codiceFiscale, new ProfessionalRecordData(...))`, nella stessa transazione del database del cambio. `declared_at` è il momento del cambio, non quello dell'invio.
- L'anagrafica è sempre completa: un campo facoltativo a `null` toglie il dato. Non mandare solo i campi cambiati. L'email è obbligatoria e va in minuscolo (T6), come nella controparte persona.
- Non chiamare `Platform::declareProfessional()` a mano: l'outbox ripete il 404 finché ci sono transazioni alla persona non ancora registrate, e senza la ripetizione il cambio va perso (R18).
- Una dichiarazione `discarded` non è un errore: il sistema non ha incaricato quella persona, o il codice fiscale è stato corretto. Il professionista nasce dal prossimo incarico.
- Le transazioni non si toccano per un cambio dell'anagrafica: ognuna porta quella del suo invio, identica a ogni revisione (R13).

## Errori

- `422`: il contratto è violato. Non ritentare: leggi `->errors`, le cui chiavi sono i campi in notazione puntata. L'unica eccezione è `ConcurrentMemberSyncException` di `syncMembers()`, che si ritenta con lo stesso elenco. I 422 della scelta sono `ApplicationNotSelectableException` e `TransactionNotPublishedException`, con i messaggi in `->messages`.
- `401`: il client rinnova il token da solo. Non mettere in cache il token a mano.
- `403`: leggi `->reason` (`pending`, `suspended`, `revoked`). È una questione di collegamento, da sistemare con l'operatore del portale, non nel codice.
- `5xx` ed errori di rete: il client riprova da solo qualche volta; dopo, la stessa busta si può rimandare.
- `429` (T7): il client aspetta da solo un `Retry-After` breve e ripete; uno più lungo è una `PlatformRequestException` con `isThrottled()` e i secondi in `->retryAfter`, da ripetere dopo quel tempo. Le outbox lo fanno da sole: rimettono il job in coda dopo `Retry-After`.

## Segreti e configurazione

- Client secret e segreto di firma stanno solo nel `.env` del server: mai nel frontend, in una vista o in un log.
- Leggi la configurazione con `config('platform.*')` o `ConnectorConfig`, mai con `env()` nel codice applicativo.
