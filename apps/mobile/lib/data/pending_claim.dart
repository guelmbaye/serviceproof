import 'dart:math';

/// A claim written on the handset that has not reached the server yet.
///
/// The idempotency key is generated here, once, and reused on every retry.
/// That is what makes the offline path safe: the technician can hit send
/// five times in a tunnel and operations still sees exactly one claim.
class PendingClaim {
  PendingClaim({
    required this.idempotencyKey,
    required this.workOrderId,
    required this.workOrderReference,
    required this.claimedAt,
    required this.queuedAt,
    this.notes,
    this.claimType = 'SERVICE_COMPLETED',
    this.attempts = 0,
    this.lastError,
  });

  factory PendingClaim.create({
    required String workOrderId,
    required String workOrderReference,
    required DateTime claimedAt,
    String? notes,
    String claimType = 'SERVICE_COMPLETED',
  }) {
    final random = Random.secure();
    final suffix = List<int>.generate(8, (_) => random.nextInt(256))
        .map((byte) => byte.toRadixString(16).padLeft(2, '0'))
        .join();

    return PendingClaim(
      idempotencyKey: 'field-${DateTime.now().microsecondsSinceEpoch}-$suffix',
      workOrderId: workOrderId,
      workOrderReference: workOrderReference,
      claimedAt: claimedAt,
      queuedAt: DateTime.now(),
      notes: notes,
      claimType: claimType,
    );
  }

  factory PendingClaim.fromJson(Map<String, dynamic> json) => PendingClaim(
        idempotencyKey: json['idempotency_key'] as String? ?? '',
        workOrderId: json['work_order_id'] as String? ?? '',
        workOrderReference: json['work_order_reference'] as String? ?? '',
        claimedAt: DateTime.tryParse(json['claimed_at'] as String? ?? '') ?? DateTime.now(),
        queuedAt: DateTime.tryParse(json['queued_at'] as String? ?? '') ?? DateTime.now(),
        notes: json['notes'] as String?,
        claimType: json['claim_type'] as String? ?? 'SERVICE_COMPLETED',
        attempts: (json['attempts'] as num?)?.toInt() ?? 0,
        lastError: json['last_error'] as String?,
      );

  final String idempotencyKey;
  final String workOrderId;
  final String workOrderReference;

  /// When the work was actually finished — captured on the handset, so an
  /// hour spent queued underground does not move the claimed time.
  final DateTime claimedAt;

  final DateTime queuedAt;
  final String? notes;
  final String claimType;
  final int attempts;
  final String? lastError;

  PendingClaim copyWith({int? attempts, String? lastError}) => PendingClaim(
        idempotencyKey: idempotencyKey,
        workOrderId: workOrderId,
        workOrderReference: workOrderReference,
        claimedAt: claimedAt,
        queuedAt: queuedAt,
        notes: notes,
        claimType: claimType,
        attempts: attempts ?? this.attempts,
        lastError: lastError,
      );

  Map<String, dynamic> toJson() => <String, dynamic>{
        'idempotency_key': idempotencyKey,
        'work_order_id': workOrderId,
        'work_order_reference': workOrderReference,
        'claimed_at': claimedAt.toIso8601String(),
        'queued_at': queuedAt.toIso8601String(),
        'notes': notes,
        'claim_type': claimType,
        'attempts': attempts,
        'last_error': lastError,
      };

  /// The request body sent to Laravel.
  ///
  /// Note what is absent: no latitude, no longitude, no accuracy. This app
  /// deliberately never reports its own position — self-reported location
  /// is exactly the evidence ServiceProof exists to replace.
  Map<String, dynamic> toRequestBody() => <String, dynamic>{
        'claim_type': claimType,
        'claimed_at': claimedAt.toUtc().toIso8601String(),
        if (notes != null && notes!.trim().isNotEmpty) 'notes': notes!.trim(),
        'idempotency_key': idempotencyKey,
        'context': <String, dynamic>{
          'offline_captured_at': queuedAt.toUtc().toIso8601String(),
        },
      };
}
