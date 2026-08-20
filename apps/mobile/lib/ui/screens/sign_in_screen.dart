import 'package:flutter/material.dart';

import '../../state/session_controller.dart';
import '../theme.dart';
import '../widgets/common.dart';

class SignInScreen extends StatefulWidget {
  const SignInScreen({super.key, required this.session});

  final SessionController session;

  @override
  State<SignInScreen> createState() => _SignInScreenState();
}

class _SignInScreenState extends State<SignInScreen> {
  final TextEditingController _email =
      TextEditingController(text: 'tech@acme-field.test');
  final TextEditingController _password = TextEditingController(text: 'password');

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final ok = await widget.session.signIn(_email.text.trim(), _password.text);

    if (!ok && mounted) {
      final message = widget.session.error;
      if (message != null) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: ListenableBuilder(
          listenable: widget.session,
          builder: (BuildContext context, _) {
            final busy = widget.session.busy;

            return SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(20, 48, 20, 24),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  Align(
                    alignment: Alignment.centerLeft,
                    child: Image.asset(
                      'assets/logo.png',
                      height: 46,
                      // Decorative here: the headline underneath already
                      // names the product to a screen reader.
                      excludeFromSemantics: true,
                    ),
                  ),
                  const SizedBox(height: 24),
                  const Text(
                    'Finish the job.\nThe network confirms it.',
                    style: TextStyle(
                      fontSize: 30,
                      height: 1.1,
                      fontWeight: FontWeight.w700,
                      letterSpacing: -0.6,
                    ),
                  ),
                  const SizedBox(height: 12),
                  const Text(
                    'No photos to take. No location to pin. Mark the work complete and '
                    'your operator answers for you.',
                    style: TextStyle(fontSize: 15, color: SpColors.ink2, height: 1.45),
                  ),
                  const SizedBox(height: 36),
                  TextField(
                    controller: _email,
                    enabled: !busy,
                    keyboardType: TextInputType.emailAddress,
                    autocorrect: false,
                    decoration: const InputDecoration(labelText: 'Work email'),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _password,
                    enabled: !busy,
                    obscureText: true,
                    onSubmitted: (_) => busy ? null : _submit(),
                    decoration: const InputDecoration(labelText: 'Password'),
                  ),
                  const SizedBox(height: 20),
                  FilledButton(
                    onPressed: busy ? null : _submit,
                    child: Text(busy ? 'Signing in…' : 'Sign in'),
                  ),
                  const SizedBox(height: 28),
                  const Text(
                    'Demo handset: tech@acme-field.test · password',
                    textAlign: TextAlign.center,
                    style: TextStyle(fontSize: 13, color: SpColors.ink3),
                  ),
                ],
              ),
            );
          },
        ),
      ),
    );
  }
}
