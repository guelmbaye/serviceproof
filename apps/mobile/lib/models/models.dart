/// Hand-written parsers against the Laravel API resources.
///
/// No codegen: one less build step to fail, and the mapping stays readable
/// next to the API reference in docs/03-api-reference.md.

/// What the network concluded about a claim.
///
/// UNVERIFIED is not a failure and must never be presented as one. It means
/// no usable evidence came back — an absence, not an accusation.
enum DecisionState {
  verified,
  partial,
  disputed,
  unverified;

  static DecisionState? parse(String? value) => switch (value) {
        'VERIFIED' => DecisionState.verified,
        'PARTIAL' => DecisionState.partial,
        'DISPUTED' => DecisionState.disputed,
        'UNVERIFIED' => DecisionState.unverified,
        _ => null,
      };

  String get label => switch (this) {
        DecisionState.verified => 'Verified',
        DecisionState.partial => 'Partly verified',
        DecisionState.disputed => 'Needs review',
        DecisionState.unverified => 'Not verified',
      };

  /// Written for the technician who submitted the claim, not for an
  /// operations analyst. It says what happens next and does not accuse.
  String get explanation => switch (this) {
        DecisionState.verified =>
          'The network evidence supports your claim. Nothing more is needed from you.',
        DecisionState.partial =>
          'Some evidence supports your claim, but not everything this job requires. '
              'Operations will take it from here.',
        DecisionState.disputed =>
          'One of the network signals does not line up with this claim, so a person '
              'will look at it. That is a normal step, not an accusation.',
        DecisionState.unverified =>
          'The network could not answer this time, so there is nothing to check your '
              'claim against. Your claim still stands; operations will confirm it another way.',
      };
}

class SessionUser {
  const SessionUser({
    required this.id,
    required this.name,
    required this.email,
    required this.role,
    this.employeeReference,
    this.organizationName,
  });

  factory SessionUser.fromJson(Map<String, dynamic> json) {
    final organization = json['organization'];

    return SessionUser(
      id: json['id'] as String? ?? '',
      name: json['name'] as String? ?? '',
      email: json['email'] as String? ?? '',
      role: json['role'] as String? ?? 'FIELD_WORKER',
      employeeReference: json['employee_reference'] as String?,
      organizationName:
          organization is Map<String, dynamic> ? organization['name'] as String? : null,
    );
  }

  final String id;
  final String name;
  final String email;
  final String role;
  final String? employeeReference;
  final String? organizationName;

  bool get isFieldWorker => role == 'FIELD_WORKER';

  Map<String, dynamic> toJson() => <String, dynamic>{
        'id': id,
        'name': name,
        'email': email,
        'role': role,
        'employee_reference': employeeReference,
        'organization': <String, dynamic>{'name': organizationName},
      };
}

class Site {
  const Site({this.name, this.address, this.latitude, this.longitude, this.radiusM});

  factory Site.fromJson(Map<String, dynamic> json) => Site(
        name: json['name'] as String?,
        address: json['address'] as String?,
        latitude: (json['latitude'] as num?)?.toDouble(),
        longitude: (json['longitude'] as num?)?.toDouble(),
        radiusM: (json['radius_m'] as num?)?.toInt(),
      );

  final String? name;
  final String? address;
  final double? latitude;
  final double? longitude;
  final int? radiusM;
}

class WorkOrder {
  const WorkOrder({
    required this.id,
    required this.reference,
    required this.status,
    required this.site,
    this.customer,
    this.serviceType,
    this.description,
    this.riskLevel,
    this.scheduledAt,
    this.windowStartsAt,
    this.windowEndsAt,
    this.deviceReference,
    this.claims = const <Claim>[],
  });

  factory WorkOrder.fromJson(Map<String, dynamic> json) {
    final device = json['device'];
    final window = json['window'];
    final rawClaims = json['claims'];

    return WorkOrder(
      id: json['id'] as String? ?? '',
      reference: json['reference'] as String? ?? '',
      status: json['status'] as String? ?? '',
      site: Site.fromJson(
        json['site'] is Map<String, dynamic>
            ? json['site'] as Map<String, dynamic>
            : const <String, dynamic>{},
      ),
      customer: json['customer'] as String?,
      serviceType: json['service_type'] as String?,
      description: json['description'] as String?,
      riskLevel: json['risk_level'] as String?,
      scheduledAt: _date(json['scheduled_at']),
      windowStartsAt: window is Map<String, dynamic> ? _date(window['starts_at']) : null,
      windowEndsAt: window is Map<String, dynamic> ? _date(window['ends_at']) : null,
      deviceReference:
          device is Map<String, dynamic> ? device['reference'] as String? : null,
      claims: rawClaims is List
          ? rawClaims
              .whereType<Map<String, dynamic>>()
              .map(Claim.fromJson)
              .toList(growable: false)
          : const <Claim>[],
    );
  }

  final String id;
  final String reference;
  final String status;
  final Site site;
  final String? customer;
  final String? serviceType;
  final String? description;
  final String? riskLevel;
  final DateTime? scheduledAt;
  final DateTime? windowStartsAt;
  final DateTime? windowEndsAt;
  final String? deviceReference;
  final List<Claim> claims;

  /// A job stops accepting claims once one has been submitted against it.
  ///
  /// The list is the WorkOrderStatus enum, not a guess: an earlier version
  /// tested for 'ASSIGNED', which the backend has never had. It was a dead
  /// branch rather than a visible failure, which is exactly why it survived —
  /// the two real values carried the behaviour and the third did nothing.
  ///
  /// AWAITING_VERIFICATION deliberately returns false. The claim is already
  /// in, and offering the button again would invite a technician to submit
  /// the same job twice.
  static const Set<String> _openToClaims = <String>{'SCHEDULED', 'IN_PROGRESS'};

  bool get acceptsClaim => _openToClaims.contains(status);

  bool get isHighRisk => riskLevel == 'HIGH';
}

class Claim {
  const Claim({
    required this.id,
    required this.reference,
    required this.status,
    this.claimedAt,
    this.notes,
    this.decisionState,
    this.decisionRationale,
    this.workOrderReference,
    this.customer,
    this.siteName,
  });

  factory Claim.fromJson(Map<String, dynamic> json) {
    final decision = json['decision'];
    final workOrder = json['work_order'];

    return Claim(
      id: json['id'] as String? ?? '',
      reference: json['reference'] as String? ?? '',
      status: json['status'] as String? ?? '',
      claimedAt: _date(json['claimed_at']),
      notes: json['notes'] as String?,
      decisionState: decision is Map<String, dynamic>
          ? DecisionState.parse(decision['state'] as String?)
          : null,
      decisionRationale:
          decision is Map<String, dynamic> ? decision['rationale'] as String? : null,
      workOrderReference:
          workOrder is Map<String, dynamic> ? workOrder['reference'] as String? : null,
      customer: workOrder is Map<String, dynamic> ? workOrder['customer'] as String? : null,
      siteName: workOrder is Map<String, dynamic> ? workOrder['site'] as String? : null,
    );
  }

  final String id;
  final String reference;
  final String status;
  final DateTime? claimedAt;
  final String? notes;
  final DecisionState? decisionState;
  final String? decisionRationale;
  final String? workOrderReference;
  final String? customer;
  final String? siteName;

  bool get awaitingOutcome => decisionState == null;
}

DateTime? _date(Object? value) =>
    value is String ? DateTime.tryParse(value)?.toLocal() : null;
