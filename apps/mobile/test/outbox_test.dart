import 'package:flutter_test/flutter_test.dart';
import 'package:serviceproof_field/data/pending_claim.dart';
import 'package:serviceproof_field/models/models.dart';

void main() {
  group('PendingClaim', () {
    test('generates a unique idempotency key per claim', () {
      final a = PendingClaim.create(
        workOrderId: 'wo-1',
        workOrderReference: 'WO-1042',
        claimedAt: DateTime.now(),
      );
      final b = PendingClaim.create(
        workOrderId: 'wo-1',
        workOrderReference: 'WO-1042',
        claimedAt: DateTime.now(),
      );

      expect(a.idempotencyKey, isNotEmpty);
      expect(a.idempotencyKey, isNot(equals(b.idempotencyKey)));
    });

    test('keeps its key across a retry, so a retry cannot duplicate a claim', () {
      final claim = PendingClaim.create(
        workOrderId: 'wo-1',
        workOrderReference: 'WO-1042',
        claimedAt: DateTime.now(),
      );

      final retried = claim.copyWith(attempts: 3, lastError: 'No connection.');

      expect(retried.idempotencyKey, equals(claim.idempotencyKey));
      expect(retried.attempts, 3);
      expect(retried.claimedAt, equals(claim.claimedAt));
    });

    test('survives a round trip through local storage', () {
      final claim = PendingClaim.create(
        workOrderId: 'wo-1',
        workOrderReference: 'WO-1042',
        claimedAt: DateTime.parse('2026-08-15T06:35:00Z'),
        notes: 'Replaced the module.',
      );

      final restored = PendingClaim.fromJson(claim.toJson());

      expect(restored.idempotencyKey, claim.idempotencyKey);
      expect(restored.workOrderReference, 'WO-1042');
      expect(restored.notes, 'Replaced the module.');
      expect(restored.claimedAt.toUtc(), claim.claimedAt.toUtc());
    });

    test('never reports the handset position', () {
      final body = PendingClaim.create(
        workOrderId: 'wo-1',
        workOrderReference: 'WO-1042',
        claimedAt: DateTime.now(),
      ).toRequestBody();

      final context = body['context'] as Map<String, dynamic>;

      expect(context.containsKey('app_latitude'), isFalse);
      expect(context.containsKey('app_longitude'), isFalse);
      expect(context.containsKey('offline_captured_at'), isTrue);
    });

    test('sends the time the work finished, not the time it was sent', () {
      final finished = DateTime.now().subtract(const Duration(hours: 2));

      final body = PendingClaim.create(
        workOrderId: 'wo-1',
        workOrderReference: 'WO-1042',
        claimedAt: finished,
      ).toRequestBody();

      expect(body['claimed_at'], finished.toUtc().toIso8601String());
    });
  });

  group('DecisionState', () {
    test('parses every state the API can return', () {
      expect(DecisionState.parse('VERIFIED'), DecisionState.verified);
      expect(DecisionState.parse('PARTIAL'), DecisionState.partial);
      expect(DecisionState.parse('DISPUTED'), DecisionState.disputed);
      expect(DecisionState.parse('UNVERIFIED'), DecisionState.unverified);
      expect(DecisionState.parse('SOMETHING_NEW'), isNull);
      expect(DecisionState.parse(null), isNull);
    });

    test('never accuses the technician', () {
      for (final state in DecisionState.values) {
        final text = '${state.label} ${state.explanation}'.toLowerCase();

        expect(text.contains('fraud'), isFalse, reason: '$state mentions fraud');
        expect(text.contains('lying'), isFalse, reason: '$state accuses');
        expect(text.contains('false claim'), isFalse, reason: '$state accuses');
      }
    });

    test('treats an unavailable network as an absence, not a failure', () {
      final text = DecisionState.unverified.explanation.toLowerCase();

      expect(text.contains('could not answer'), isTrue);
      expect(text.contains('still stands'), isTrue);
    });
  });

  group('Claim parsing', () {
    test('reads the API resource shape', () {
      final claim = Claim.fromJson(<String, dynamic>{
        'id': 'c1',
        'reference': 'CLM-1043',
        'status': 'RESOLVED',
        'claimed_at': '2026-08-15T06:35:00+00:00',
        'notes': 'Replaced the ONT.',
        'work_order': <String, dynamic>{
          'reference': 'WO-1043',
          'customer': 'Meridian Facilities',
          'site': 'Site B',
        },
        'decision': <String, dynamic>{'state': 'DISPUTED', 'rationale': 'A signal conflicts.'},
      });

      expect(claim.reference, 'CLM-1043');
      expect(claim.decisionState, DecisionState.disputed);
      expect(claim.workOrderReference, 'WO-1043');
      expect(claim.awaitingOutcome, isFalse);
    });

    test('a claim with no decision yet is awaiting an outcome', () {
      final claim = Claim.fromJson(<String, dynamic>{
        'id': 'c2',
        'reference': 'CLM-1044',
        'status': 'SUBMITTED',
      });

      expect(claim.decisionState, isNull);
      expect(claim.awaitingOutcome, isTrue);
    });
  });


  group('Work order status', () {
    WorkOrder withStatus(String status) => WorkOrder.fromJson(<String, dynamic>{
          'id': 'w1',
          'reference': 'WO-1042',
          'status': status,
          'site': <String, dynamic>{'name': 'Site A'},
        });

    test('only the two genuinely open statuses accept a claim', () {
      expect(withStatus('SCHEDULED').acceptsClaim, isTrue);
      expect(withStatus('IN_PROGRESS').acceptsClaim, isTrue);
    });

    test('a job already awaiting verification does not offer the button again', () {
      // The claim is in. Offering it again invites a duplicate submission.
      expect(withStatus('AWAITING_VERIFICATION').acceptsClaim, isFalse);
    });

    test('a decided job never accepts another claim', () {
      for (final status in <String>['VERIFIED', 'DISPUTED', 'NEEDS_REVIEW', 'CLOSED', 'CANCELLED']) {
        expect(withStatus(status).acceptsClaim, isFalse, reason: status);
      }
    });

    test('every status the backend can send is covered', () {
      // The full WorkOrderStatus enum. An earlier version tested for
      // 'ASSIGNED', which the backend has never had.
      const backendStatuses = <String>[
        'SCHEDULED', 'IN_PROGRESS', 'AWAITING_VERIFICATION', 'VERIFIED',
        'DISPUTED', 'NEEDS_REVIEW', 'CLOSED', 'CANCELLED',
      ];

      final accepting = backendStatuses.where((s) => withStatus(s).acceptsClaim).toList();

      expect(accepting, <String>['SCHEDULED', 'IN_PROGRESS']);
    });

    test('an unknown future status fails closed', () {
      expect(withStatus('SOMETHING_NEW').acceptsClaim, isFalse);
    });
  });
}
