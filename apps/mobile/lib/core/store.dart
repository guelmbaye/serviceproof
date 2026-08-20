import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

/// Local persistence: the session token and the unsent-claim outbox.
///
/// Honest limitation: SharedPreferences is not a keychain. On Android the
/// token sits in app-private storage, which is adequate for a demo and for
/// a managed fleet, but a production build should move it to the platform
/// keystore. This is called out in the README rather than papered over.
class Store {
  Store(this._prefs);

  static const String _tokenKey = 'sp.token';
  static const String _userKey = 'sp.user';
  static const String _outboxKey = 'sp.outbox';

  final SharedPreferences _prefs;

  static Future<Store> open() async => Store(await SharedPreferences.getInstance());

  String? get token => _prefs.getString(_tokenKey);

  Future<void> saveToken(String token) => _prefs.setString(_tokenKey, token);

  Map<String, dynamic>? get user {
    final raw = _prefs.getString(_userKey);
    if (raw == null) return null;

    final decoded = jsonDecode(raw);
    return decoded is Map<String, dynamic> ? decoded : null;
  }

  Future<void> saveUser(Map<String, dynamic> user) =>
      _prefs.setString(_userKey, jsonEncode(user));

  Future<void> clearSession() async {
    await _prefs.remove(_tokenKey);
    await _prefs.remove(_userKey);
  }

  List<Map<String, dynamic>> get outbox {
    final raw = _prefs.getStringList(_outboxKey) ?? const <String>[];

    return raw
        .map<Map<String, dynamic>?>((entry) {
          final decoded = jsonDecode(entry);
          return decoded is Map<String, dynamic> ? decoded : null;
        })
        .whereType<Map<String, dynamic>>()
        .toList();
  }

  Future<void> saveOutbox(List<Map<String, dynamic>> entries) => _prefs.setStringList(
        _outboxKey,
        entries.map(jsonEncode).toList(),
      );
}
