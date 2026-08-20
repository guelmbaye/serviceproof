/// Build-time configuration.
///
/// Passed with --dart-define so no host or secret ever sits in the repo:
///
///   flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000/api/v1
///
/// 10.0.2.2 is the host machine as seen from the Android emulator.
/// Use your laptop's LAN address for a physical handset.
class Config {
  const Config._();

  static const String apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8000/api/v1',
  );

  static const String deviceName = String.fromEnvironment(
    'DEVICE_NAME',
    defaultValue: 'field-app',
  );

  /// How long to wait before deciding the network is not going to answer.
  /// Short on purpose: a technician in a basement should reach the offline
  /// path quickly rather than watch a spinner.
  static const Duration requestTimeout = Duration(seconds: 12);
}
