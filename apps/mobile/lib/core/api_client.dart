import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

import 'config.dart';

/// The API rejected the request and told us why.
class ApiException implements Exception {
  ApiException(this.statusCode, this.message, [this.details]);

  final int statusCode;
  final String message;
  final Map<String, dynamic>? details;

  @override
  String toString() => message;
}

/// The session is gone. The caller signs out rather than showing an error.
class SessionExpired implements Exception {}

/// We could not reach the API at all.
///
/// A distinct type because it drives completely different behaviour: a
/// claim that fails this way is queued, not lost.
class Offline implements Exception {
  Offline(this.reason);

  final String reason;

  @override
  String toString() => reason;
}

class ApiClient {
  ApiClient({http.Client? client}) : _client = client ?? http.Client();

  final http.Client _client;
  String? _token;

  set token(String? value) => _token = value;

  String? get token => _token;

  Map<String, String> get _headers => <String, String>{
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        if (_token != null) 'Authorization': 'Bearer $_token',
      };

  Future<Map<String, dynamic>> get(String path) => _send('GET', path);

  Future<Map<String, dynamic>> post(String path, [Map<String, dynamic>? body]) =>
      _send('POST', path, body);

  Future<Map<String, dynamic>> _send(
    String method,
    String path, [
    Map<String, dynamic>? body,
  ]) async {
    final uri = Uri.parse('${Config.apiBaseUrl}$path');

    http.Response response;

    try {
      final request = http.Request(method, uri)..headers.addAll(_headers);
      if (body != null) request.body = jsonEncode(body);

      final streamed = await _client.send(request).timeout(Config.requestTimeout);
      response = await http.Response.fromStream(streamed);
    } on SocketException catch (error) {
      throw Offline(error.osError?.message ?? 'No connection to the server.');
    } on TimeoutException {
      throw Offline('The server did not answer in time.');
    } on http.ClientException catch (error) {
      throw Offline(error.message);
    }

    if (response.statusCode == 401) throw SessionExpired();

    Map<String, dynamic> payload;

    try {
      final decoded = jsonDecode(response.body);
      payload = decoded is Map<String, dynamic> ? decoded : <String, dynamic>{};
    } on FormatException {
      payload = <String, dynamic>{};
    }

    if (response.statusCode >= 400) {
      final error = payload['error'];

      if (error is Map<String, dynamic>) {
        throw ApiException(
          response.statusCode,
          _firstMessage(error) ?? 'The request was refused.',
          error['details'] is Map<String, dynamic>
              ? error['details'] as Map<String, dynamic>
              : null,
        );
      }

      throw ApiException(response.statusCode, 'The request was refused.');
    }

    return payload;
  }

  /// A field validation message is more useful than the generic envelope
  /// text, so surface the first one when there is one.
  String? _firstMessage(Map<String, dynamic> error) {
    final details = error['details'];

    if (details is Map<String, dynamic> && details.isNotEmpty) {
      final first = details.values.first;
      if (first is List && first.isNotEmpty) return first.first.toString();
      if (first is String) return first;
    }

    final message = error['message'];
    return message is String ? message : null;
  }

  void close() => _client.close();
}
