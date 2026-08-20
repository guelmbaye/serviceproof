import '../core/api_client.dart';
import '../models/models.dart';
import 'pending_claim.dart';

class AuthRepository {
  AuthRepository(this._api);

  final ApiClient _api;

  Future<({String token, SessionUser user})> signIn(
    String email,
    String password,
    String deviceName,
  ) async {
    final response = await _api.post('/auth/login', <String, dynamic>{
      'email': email,
      'password': password,
      'device_name': deviceName,
    });

    final token = response['token'] as String?;
    final rawUser = response['user'];

    if (token == null) {
      throw ApiException(500, 'The server did not return a session token.');
    }

    final userJson = rawUser is Map<String, dynamic>
        ? (rawUser['data'] is Map<String, dynamic>
            ? rawUser['data'] as Map<String, dynamic>
            : rawUser)
        : <String, dynamic>{};

    return (token: token, user: SessionUser.fromJson(userJson));
  }

  Future<SessionUser> me() async {
    final response = await _api.get('/auth/me');
    final data = response['data'];

    return SessionUser.fromJson(
      data is Map<String, dynamic> ? data : <String, dynamic>{},
    );
  }

  Future<void> signOut() async {
    await _api.post('/auth/logout');
  }
}

class WorkOrderRepository {
  WorkOrderRepository(this._api);

  final ApiClient _api;

  /// A field worker's list is scoped to their own assignments by the API
  /// itself, not by anything this client sends. `open_only` just trims the
  /// closed jobs off the end.
  Future<List<WorkOrder>> assignedToMe() async {
    final response = await _api.get('/work-orders?open_only=1&per_page=50');
    final data = response['data'];

    if (data is! List) return const <WorkOrder>[];

    return data
        .whereType<Map<String, dynamic>>()
        .map(WorkOrder.fromJson)
        .toList(growable: false);
  }

  Future<WorkOrder> find(String id) async {
    final response = await _api.get('/work-orders/$id');
    final data = response['data'];

    return WorkOrder.fromJson(
      data is Map<String, dynamic> ? data : <String, dynamic>{},
    );
  }
}

class ClaimRepository {
  ClaimRepository(this._api);

  final ApiClient _api;

  /// Submit one queued claim. Retrying with the same idempotency key is
  /// safe: the server returns the original claim rather than creating a
  /// second one.
  Future<Claim> submit(PendingClaim pending) async {
    final response = await _api.post(
      '/work-orders/${pending.workOrderId}/claims',
      pending.toRequestBody(),
    );

    final data = response['data'];

    return Claim.fromJson(
      data is Map<String, dynamic> ? data : <String, dynamic>{},
    );
  }

  Future<List<Claim>> mine() async {
    final response = await _api.get('/claims?per_page=50');
    final data = response['data'];

    if (data is! List) return const <Claim>[];

    return data
        .whereType<Map<String, dynamic>>()
        .map(Claim.fromJson)
        .toList(growable: false);
  }

  Future<Claim> find(String id) async {
    final response = await _api.get('/claims/$id');
    final data = response['data'];

    return Claim.fromJson(
      data is Map<String, dynamic> ? data : <String, dynamic>{},
    );
  }
}
