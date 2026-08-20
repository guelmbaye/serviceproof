import 'package:flutter/foundation.dart';

import '../core/api_client.dart';
import '../core/store.dart';
import '../data/pending_claim.dart';
import '../data/repositories.dart';
import '../models/models.dart';

/// The offline outbox.
///
/// A technician finishes a job in a basement, a lift shaft, or a rural
/// substation. The claim is written to disk immediately and sent when there
/// is signal. Every retry reuses the same idempotency key, so operations
/// never sees a duplicate no matter how many times the handset tries.
class OutboxController extends ChangeNotifier {
  OutboxController({required ApiClient api, required Store store})
      : _store = store,
        _claims = ClaimRepository(api);

  final Store _store;
  final ClaimRepository _claims;

  List<PendingClaim> pending = <PendingClaim>[];
  bool flushing = false;

  /// Claims that reached the server during this session, by work order —
  /// so the job screen can show an outcome without another round trip.
  final Map<String, Claim> submitted = <String, Claim>{};

  int get count => pending.length;

  bool hasPendingFor(String workOrderId) =>
      pending.any((claim) => claim.workOrderId == workOrderId);

  Future<void> load() async {
    pending = _store.outbox.map(PendingClaim.fromJson).toList();
    notifyListeners();
  }

  /// Queue a claim and try to send it straight away.
  ///
  /// Returns the server's claim when it went through immediately, or null
  /// when it is sitting in the outbox. Either way the technician's work is
  /// recorded — the difference is only whether it has left the handset.
  Future<Claim?> enqueue(PendingClaim claim) async {
    pending = <PendingClaim>[...pending, claim];
    await _persist();
    notifyListeners();

    await flush();

    return submitted[claim.workOrderId];
  }

  /// Try to send everything waiting. Safe to call as often as you like.
  Future<void> flush() async {
    if (flushing || pending.isEmpty) return;

    flushing = true;
    notifyListeners();

    final remaining = <PendingClaim>[];
    var stop = false;

    try {
      for (final claim in pending) {
        if (stop) {
          // Everything after the failure keeps its place in the queue.
          remaining.add(claim);
          continue;
        }

        try {
          final result = await _claims.submit(claim);
          submitted[claim.workOrderId] = result;
        } on Offline catch (offline) {
          // Still no signal. Keep this claim and stop trying the rest:
          // order is the technician's order of work, and it is worth
          // preserving.
          remaining.add(claim.copyWith(
            attempts: claim.attempts + 1,
            lastError: offline.reason,
          ));
          stop = true;
        } on SessionExpired {
          // Nothing can be sent until the technician signs in again. Keep
          // the queue exactly as it is — nothing here is lost.
          remaining.add(claim);
          stop = true;
        } on ApiException catch (error) {
          // A 5xx is the server being unwell, not a problem with the claim,
          // so it stays queued. A 4xx is the server refusing this claim on
          // its merits — the job is closed, or the technician is no longer
          // assigned — and retrying will not help, so the reason is kept on
          // the entry for the technician to see rather than looped over.
          remaining.add(claim.copyWith(
            attempts: claim.attempts + 1,
            lastError: error.message,
          ));
          if (error.statusCode >= 500) stop = true;
        }
      }

      pending = remaining;
      await _persist();
    } finally {
      // Whatever happened, the queue must never be left locked.
      flushing = false;
      notifyListeners();
    }
  }

  Future<void> discard(String idempotencyKey) async {
    pending = pending
        .where((claim) => claim.idempotencyKey != idempotencyKey)
        .toList(growable: false);

    await _persist();
    notifyListeners();
  }

  Future<void> _persist() =>
      _store.saveOutbox(pending.map((claim) => claim.toJson()).toList());
}
