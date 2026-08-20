import 'package:flutter/foundation.dart';

import '../core/api_client.dart';
import '../core/store.dart';
import '../data/repositories.dart';
import '../models/models.dart';

enum SessionStatus { starting, signedOut, signedIn }

/// Session state for the whole app.
///
/// ChangeNotifier rather than a state-management package: four screens do
/// not need a dependency, and one less package is one less thing that fails
/// to resolve on a hackathon laptop.
class SessionController extends ChangeNotifier {
  SessionController({
    required ApiClient api,
    required Store store,
  })  : _api = api,
        _store = store,
        _auth = AuthRepository(api);

  final ApiClient _api;
  final Store _store;
  final AuthRepository _auth;

  SessionStatus status = SessionStatus.starting;
  SessionUser? user;
  String? error;
  bool busy = false;

  /// Restore a session from disk so a technician who reopens the app in a
  /// dead zone still sees their jobs and their outbox.
  Future<void> restore() async {
    final token = _store.token;

    if (token == null) {
      status = SessionStatus.signedOut;
      notifyListeners();
      return;
    }

    _api.token = token;

    final cached = _store.user;
    if (cached != null) user = SessionUser.fromJson(cached);

    status = SessionStatus.signedIn;
    notifyListeners();

    // Refresh in the background. Offline is fine — the cached identity
    // stands until the server actually rejects the token.
    try {
      final fresh = await _auth.me();
      user = fresh;
      await _store.saveUser(fresh.toJson());
      notifyListeners();
    } on SessionExpired {
      await signOut(revokeRemotely: false);
    } on Offline {
      // Keep going with what we have.
    } on ApiException {
      // Same: a transient server error must not sign a technician out.
    }
  }

  Future<bool> signIn(String email, String password) async {
    busy = true;
    error = null;
    notifyListeners();

    try {
      final result = await _auth.signIn(email, password, 'field-app');

      _api.token = result.token;
      user = result.user;

      await _store.saveToken(result.token);
      await _store.saveUser(result.user.toJson());

      status = SessionStatus.signedIn;
      return true;
    } on Offline catch (offline) {
      error = 'Cannot reach the server. ${offline.reason}';
      return false;
    } on ApiException catch (api) {
      error = api.message;
      return false;
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  Future<void> signOut({bool revokeRemotely = true}) async {
    if (revokeRemotely) {
      try {
        await _auth.signOut();
      } on Object {
        // Signing out locally is the part that matters. A token we cannot
        // revoke now expires on its own.
      }
    }

    _api.token = null;
    user = null;
    await _store.clearSession();

    status = SessionStatus.signedOut;
    notifyListeners();
  }
}
